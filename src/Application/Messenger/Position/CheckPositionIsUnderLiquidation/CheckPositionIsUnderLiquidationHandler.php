<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position\CheckPositionIsUnderLiquidation;

use App\Application\Logger\AppErrorLoggerInterface;
use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\DynamicParameters\LiquidationDynamicParametersFactoryInterface;
use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\DynamicParameters\LiquidationDynamicParametersInterface;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\Dto\SpotBalance;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Application\Service\Orders\StopServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Repository\StopRepositoryInterface;
use App\Bot\Domain\Ticker;
use App\Domain\Coin\Coin;
use App\Domain\Coin\CoinAmount;
use App\Domain\Order\ExchangeOrder;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\PriceMovement;
use App\Domain\Price\PriceRange;
use App\Domain\Price\SymbolPrice;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Stop\StopsCollection;
use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearExchangeCacheDecoratedService;
use App\Infrastructure\Doctrine\Helper\QueryHelper;
use App\Liquidation\Application\Settings\LiquidationHandlerSettings;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;
use App\Trading\Domain\Symbol\SymbolInterface;
use App\Worker\AppContext;
use Doctrine\ORM\QueryBuilder;
use Exception;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Cache\CacheInterface;
use Throwable;

use function array_filter;
use function max;
use function min;
use function random_int;
use function sprintf;

/**
 * @see \App\Tests\Functional\Application\Messenger\Position\CheckPositionIsUnderLiquidationHandler\AddStopWhenPositionLiquidationInWarningRangeTest
 * @see \App\Tests\Unit\Application\Messenger\Position\CheckPositionIsUnderLiquidation\CheckPositionIsUnderLiquidationHandlerTest
 */
#[AsMessageHandler]
final class CheckPositionIsUnderLiquidationHandler
{
    # Transfer from spot
    public const int TRANSFER_FROM_SPOT_ON_DISTANCE = 200;
    public const int TRANSFER_AMOUNT_DIFF_WITH_BALANCE = 1;
    public const int MAX_TRANSFER_AMOUNT = 60;
    private const float TRANSFER_AMOUNT_MODIFIER = 0.2;
    private const float SPOT_TRANSFERS_BEFORE_ADD_STOP = 2.5;

    public const int CLOSE_BY_MARKET_IF_DISTANCE_LESS_THAN = 40;

    /** @var Position[] */
    private array $positions;
    /** @var array<string, SymbolPrice> */
    private array $lastMarkPrices;
    /** @var ActiveStopOrder[] */
    private array $activeConditionalStopOrders;

    ### each symbol runtime
    private LiquidationDynamicParametersInterface $dynamicParameters;

    /** @internal for tests purposes */
    public bool $onlyRemoveStale = false;

    public function __invoke(CheckPositionIsUnderLiquidation $message): void
    {
//        $start = OutputHelper::currentTimePoint();
        $this->activeConditionalStopOrders = $this->exchangeService->activeConditionalOrders();
        $this->positions = [];

        if (!$message->symbol) {
            $messages = [];

            /** @var $allPositions array<Position[]> */
            $allPositions = $this->positionService->getAllPositions();
            $this->lastMarkPrices = $this->positionService->getLastMarkPrices();
            foreach ($allPositions as $symbolPositions) {
                $first = $symbolPositions[array_key_first($symbolPositions)];
                $hedge = $first->getHedge();

                if ($hedge?->isEquivalentHedge()) {
                    continue;
                }

                $main = $hedge?->mainPosition ?? $first;
                if ($main->isShort() && !$main->liquidationPrice) {
                    continue;
                }

                $symbol = $main->symbol;

                $messages[] = new CheckPositionIsUnderLiquidation(
                    symbol: $symbol,
                    percentOfLiquidationDistanceToAddStop: $message->percentOfLiquidationDistanceToAddStop,
                    acceptableStoppedPart: $message->acceptableStoppedPart,
                    warningPnlDistance: $message->warningPnlDistance,
                );
                $this->positions[$symbol->name()] = $main;
            }
        } else {
            if (!($position = $this->getPositionOld($message->symbol))) {
                return;
            }
            $symbol = $position->symbol;

            $messages = [$message];
            $this->positions[$symbol->name()] = $position;
            $this->lastMarkPrices[$symbol->name()] = $this->exchangeService->ticker($symbol)->markPrice;
        }

        foreach ($messages as $message) {
            try {
                $this->handleMessage($message);
            } catch (Throwable $e) {
                $this->appErrorLogger->exception($e, sprintf('[CheckPositionIsUnderLiquidationHandler] Got error when try to handle %s', $message->symbol->name()));
            }
        }

//        self::timeDiffInfo($start);
    }

    public function handleMessage(CheckPositionIsUnderLiquidation $message): void
    {
        $symbol = $message->symbol;
        $position = $this->positions[$symbol->name()];

        $markPrice = $this->lastMarkPrices[$symbol->name()];
        $ticker = new Ticker($symbol, $markPrice, $markPrice, $markPrice); // @todo Get rid of ticker?
        $coin = $symbol->associatedCoin();

        // @todo можно добавить какой-то self-check (например корректность warningRange относительно criticalRange)
        $this->dynamicParameters = $this->liquidationDynamicParametersFactory->create($message, $position, $ticker);

        ### remove stale ###
        // @todo | liquidation | works only for by market =(
        foreach ($this->getStaleStops($position) as $stop) {
            $stop->setFakeExchangeOrderId();
            $this->stopRepository->save($stop);
        }

        if ($this->onlyRemoveStale) {
            return;
        }

        ### add new ###
        $distanceWithLiquidation = $position->priceDistanceWithLiquidation($ticker);
        $positionSide = $position->side;

        if (
            $distanceWithLiquidation > $this->dynamicParameters->warningDistance()
            && ($lastRunMarketPrice = $this->getLastRunMarkPrice($position)) !== null
            && PriceMovement::fromToTarget($lastRunMarketPrice, $ticker->markPrice)->isProfitFor($positionSide)
        ) {
            return; # skip checks if price didn't move to position loss direction AND liquidation is not in warning range
        }

        if ($distanceWithLiquidation <= $this->dynamicParameters->transferFromSpotOnDistance()) {
            try {
                $spotBalance = $this->getSpotBalance($coin);
                if ($spotBalance->available() > 2) {
                    $amountToTransfer = $this->getAmountToTransfer($position)->value();
                    $amountTransferred = min($amountToTransfer, $spotBalance->available->sub(self::TRANSFER_AMOUNT_DIFF_WITH_BALANCE)->value());

                    $this->exchangeAccountService->interTransferFromSpotToContract($coin, $amountTransferred);
                    $this->resetSpotBalanceCache($coin);
                }
            } catch (Throwable $e) {
                $msg = sprintf('%s: %s', OutputHelper::shortClassName(__METHOD__), $e->getMessage());
                OutputHelper::print($msg);
                $this->appErrorLogger->exception($e, sprintf('[CheckPositionIsUnderLiquidationHandler] Got error when try to transfer from spot to contract while process %s', $message->symbol->name()));
            }
        }

        $checkStopsOnDistance = $this->dynamicParameters->checkStopsOnDistance();
        if ($distanceWithLiquidation <= $checkStopsOnDistance) {
            $notCoveredSize = $position->getNotCoveredSize();
            $acceptableStoppedPart = $this->dynamicParameters->acceptableStoppedPart();
            $acceptableStoppedPartBeforeLiquidation = $acceptableStoppedPart;
            // @todo | maybe need also check that hedge has positive distance (...&& $hedge->isProfitableHedge()...)
            if ($position->getHedge()?->getSupportRate()->value() > 25) {
                // @todo | Need to be covered with tests
                $acceptableStoppedPartBeforeLiquidation = $acceptableStoppedPartBeforeLiquidation - $acceptableStoppedPart / 2.2;
            }

            $stopsBeforeLiquidationVolume = $this->getStopsVolumeBeforeLiquidation($position, $ticker);
            $stoppedPositionPart = ($stopsBeforeLiquidationVolume / $notCoveredSize) * 100; // @todo | maybe need update position before calc
            $volumePartDelta = $acceptableStoppedPartBeforeLiquidation - $stoppedPositionPart;
            if ($volumePartDelta > 0) {
                $stopQty = $symbol->roundVolumeUp(new Percent($volumePartDelta)->of($notCoveredSize));

                $closeByMarketIfDistanceLessThan = PnlHelper::convertPnlPercentOnPriceToAbsDelta(self::CLOSE_BY_MARKET_IF_DISTANCE_LESS_THAN, $position->entryPrice());
                if ($distanceWithLiquidation <= $closeByMarketIfDistanceLessThan) {
                    // @todo | liquidation | liquidation-handler | mb strict add condStop in any case? or actualize (ta)
                    $this->orderService->closeByMarket($position, $stopQty);
                } else {
//                    if ($decreaseStopDistance) $stopPriceDistance = $stopPriceDistance * 0.5;
                    $triggerDelta = $this->dynamicParameters->additionalStopTriggerDelta();
                    $stopPrice = $this->dynamicParameters->additionalStopPrice();

                    # Recalculate qty to min. Otherwise, created further BuyOrders can lead to excessive increase in position size
                    // @todo | liquidation | too big additional stop | FLM: 557.9%|1[a.-4383.2%] stop added (need some new logic for calc volume or rather fix opposite buy orders creation logic)
                    $exchangeOrder = ExchangeOrder::roundedToMin($symbol, $stopQty, $stopPrice);
                    $stopQty = $exchangeOrder->getVolume();

                    $context = [
                        Stop::IS_ADDITIONAL_STOP_FROM_LIQUIDATION_HANDLER => true,
                        Stop::CLOSE_BY_MARKET_CONTEXT => true, // @todo | settings
                        Stop::FIX_OPPOSITE_MAIN_ON_LOSS => $this->settings->required(
                            SettingAccessor::withAlternativesAllowed(LiquidationHandlerSettings::FixOppositeIfMain, $symbol, $positionSide)
                        ),
                        Stop::FIX_OPPOSITE_SUPPORT_ON_LOSS => $this->settings->required(
                            SettingAccessor::withAlternativesAllowed(LiquidationHandlerSettings::FixOppositeEvenIfSupport, $symbol, $positionSide)
                        ),
                    ];

                    if (!$this->dynamicParameters->addOppositeBuyOrdersAfterStop()) {
                        $context[Stop::WITHOUT_OPPOSITE_ORDER_CONTEXT] = true;
                    }

//                    if (!AppContext::isTest()) {
//                        $context['when'] = [
//                            'position.liquidation' => $position->liquidationPrice,
//                            '$checkStopsOnDistance' => $checkStopsOnDistance,
//                            '$distanceWithLiquidation' => $distanceWithLiquidation,
//                            '$stopPriceDistance' => $position->isShort() ? $position->liquidationPrice - $stopPrice->value() : $stopPrice->value() - $position->liquidationPrice,
//                        ];
//                    }

                    $this->stopService->create($position->symbol, $positionSide, $stopPrice, $stopQty, $triggerDelta, $context);
                }
            }
        }

        $this->setLastRunMarkPrice($position, $ticker);
    }

    private function getStopsVolumeBeforeLiquidation(Position $position, Ticker $ticker): float
    {
        $indexPrice = $ticker->indexPrice; $markPrice = $ticker->markPrice;
        $positionSide = $position->side;
        $actualStopsRange = $this->dynamicParameters->actualStopsRange();

        $boundBeforeLiquidation = $position->isShort() ? $actualStopsRange->to() : $actualStopsRange->from();
        $tickerBound = $position->isShort() ? min($indexPrice->value(), $markPrice->value()) : max($indexPrice->value(), $markPrice->value());
        $priceRangeToFindExistedStops = PriceRange::create($boundBeforeLiquidation, $tickerBound, $position->symbol);

        $delayedStops = $this->stopRepository->findActive(symbol: $position->symbol, side: $positionSide, qbModifier: function (QueryBuilder $qb) use ($priceRangeToFindExistedStops) {
            $priceField = $qb->getRootAliases()[0] . '.price';
            $from = $priceRangeToFindExistedStops->from()->value();
            $to = $priceRangeToFindExistedStops->to()->value();
            // @todo | research pgsql BETWEEN (if stopPrice equals specified bounds)
            $qb->andWhere($priceField . ' BETWEEN :priceFrom AND :priceTo')->setParameter(':priceFrom', $from)->setParameter(':priceTo', $to);
        });

        /** @todo | переделать isPriceInRange? */
        $delayedStops = (new StopsCollection(...$delayedStops))->filterWithCallback(static fn (Stop $stop) => !$stop->isTakeProfitOrder());
        $activeConditionalStops = $this->findActivePositionStopOrders($position->symbol, $positionSide, $priceRangeToFindExistedStops);

        $activeConditionalStopsVolume = 0;
        foreach ($activeConditionalStops as $activeConditionalStop) {
            $activeConditionalStopsVolume += $activeConditionalStop->volume;
        }

        return $delayedStops->totalVolume() + $activeConditionalStopsVolume;
    }

    /**
     * @return ActiveStopOrder[]
     */
    private function findActivePositionStopOrders(SymbolInterface $symbol, Side $positionSide, PriceRange $priceRange): array
    {
        return array_filter(
            $this->activeConditionalStopOrders,
            static function(ActiveStopOrder $activeStopOrder) use ($symbol, $positionSide, $priceRange) {
                return
                    $activeStopOrder->symbol->eq($symbol) &&
                    $activeStopOrder->positionSide === $positionSide &&
                    $priceRange->isPriceInRange($activeStopOrder->triggerPrice);
            }
        );
    }

    public function getAmountToTransfer(Position $position): CoinAmount
    {
//        $distanceForCalcTransferAmount = $this->distanceForCalcTransferAmount !== null ? $this->distanceForCalcTransferAmount : random_int(300, 500);
//        $amountCalcByDistance = $distanceForCalcTransferAmount * $position->getNotCoveredSize();

        return new CoinAmount($position->symbol->associatedCoin(), self::MAX_TRANSFER_AMOUNT);
    }

    private function getPositionOld(SymbolInterface $symbol): ?Position
    {
        if (!($positions = $this->positionService->getPositions($symbol))) {
            return null;
        }

        $position = $positions[0]->getHedge()?->mainPosition ?? $positions[0];

        if ($position->isShort() && !$position->liquidationPrice) {
            return null;
        }

        if ($position->getHedge()?->isEquivalentHedge()) {
            return null;
        }

        return $position;
    }

    private function getStaleStops(Position $position): StopsCollection
    {
        # if opposite position previously was main
        $oppositePositionStops = $this->stopRepository->findActive(
            symbol: $position->symbol,
            side: $position->side->getOpposite(),
            qbModifier: static fn (QueryBuilder $qb, string $alias) => StopRepository::addIsAdditionalStopFromLiqHandlerCondition($qb, $alias)
        );

        // @todo | liquidation | или сначала надо получить цену нового стопа и потом принять решение об удалении?
        // @todo | liquidation |нужна ли какая-то проверка warningRange?
        $actualRange = $this->dynamicParameters->actualStopsRange();
        $criticalRange = $this->dynamicParameters->criticalRange();

        $stopsFromOutsideTheRange = $this->stopRepository->findActive(
            symbol: $position->symbol,
            side: $position->side,
            qbModifier: function (QueryBuilder $qb, string $alias) use ($position, $actualRange) {
                $qb = StopRepository::addIsAdditionalStopFromLiqHandlerCondition($qb, $alias);
                $qb->andWhere(sprintf('%s %s :outsideRangePrice', QueryHelper::priceField($qb), $position->isShort() ? '<' : '>'));
                $qb->setParameter(':outsideRangePrice', $position->isShort() ? $actualRange->from()->value() : $actualRange->to()->value());
            }
        );

        $stopsToPositionSide = $this->stopRepository->findActive(
            symbol: $position->symbol,
            side: $position->side,
            qbModifier: function (QueryBuilder $qb, string $alias) use ($position, $actualRange) {
                $qb = StopRepository::addIsAdditionalStopFromLiqHandlerCondition($qb, $alias);
                $qb->andWhere(sprintf('%s %s :innerRangePrice', QueryHelper::priceField($qb), $position->isShort() ? '>' : '<'));
                $qb->setParameter(':innerRangePrice', $position->isShort() ? $actualRange->to()->value() : $actualRange->from()->value());
            }
        );

        $result = [...$oppositePositionStops, ...$stopsFromOutsideTheRange];

        $symbol = $position->symbol;
        foreach ($stopsToPositionSide as $stop) {
            // @todo | liquidation | мб цена вообще ниже или выше границы
            if ($symbol->makePrice($stop->getPrice())->isPriceInRange($criticalRange)) {
                continue;
            }
            $result[] = $stop;
        }

        return new StopsCollection(...$result);
    }

    private function getLastRunMarkPrice(Position $position): ?SymbolPrice
    {
        if ($this->cache === null) {
            return null;
        }

        $cacheItem = $this->cache->getItem(self::lastRunMarkPriceCacheKey($position));
        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        return null;
    }

    private function setLastRunMarkPrice(Position $position, Ticker $ticker): void
    {
        if ($this->cache === null) {
            return;
        }

        $lastPriceCrossingThresholdDefaultCacheTtl = $this->settings->required(
            SettingAccessor::withAlternativesAllowed(LiquidationHandlerSettings::LastPriceCrossingThresholdDefaultCacheTtl, $position->symbol, $position->side)
        );

        $cacheItem = $this->cache->getItem(self::lastRunMarkPriceCacheKey($position))
            ->set($ticker->markPrice)
            ->expiresAfter($lastPriceCrossingThresholdDefaultCacheTtl);

        $this->cache->save($cacheItem);
    }

    private static function lastRunMarkPriceCacheKey(Position $position): string
    {
        return sprintf('liq_handler_last_run_mark_price_%s_%s', $position->symbol->name(), $position->side->value);
    }

    private array $spotBalanceCache = [];

    private function getSpotBalance(Coin $coin): SpotBalance
    {
        if (isset($this->spotBalanceCache[$coin->value])) {
            return $this->spotBalanceCache[$coin->value];
        }

        return $this->spotBalanceCache[$coin->value] = $this->exchangeAccountService->getSpotWalletBalance($coin);
    }

    private function resetSpotBalanceCache(Coin $coin): void
    {
        unset($this->spotBalanceCache[$coin->value]);
    }

    /**
     * @param ByBitLinearExchangeCacheDecoratedService $exchangeService
     */
    public function __construct(
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly PositionServiceInterface $positionService,
        private readonly ExchangeAccountServiceInterface $exchangeAccountService,
        private readonly OrderServiceInterface $orderService,
        private readonly StopServiceInterface $stopService,
        private readonly StopRepositoryInterface $stopRepository,
        private readonly AppErrorLoggerInterface $appErrorLogger,
        private readonly ?CacheInterface $cache,
        private readonly AppSettingsProviderInterface $settings,
        private readonly LiquidationDynamicParametersFactoryInterface $liquidationDynamicParametersFactory,
        private readonly ?int $distanceForCalcTransferAmount = null,
    ) {
    }

    public static function isDebug(): bool
    {
        return AppContext::isDebug() && AppContext::isTest();
    }

    public function setOnlyRemoveStale(bool $value): void
    {
        $this->onlyRemoveStale = $value;
    }

    private static function timeDiffInfo(float $startPoint, ?string $desc = null): void
    {
        OutputHelper::printTimeDiff(sprintf('           LiqHandler%s', $desc ? sprintf(': %s', $desc) : ''), $startPoint);
    }

//    public const ADDITIONAL_STOP_TRIGGER_DEFAULT_DELTA = 150;
//    public const ADDITIONAL_STOP_TRIGGER_MID_DELTA = 30;
//    public const ADDITIONAL_STOP_TRIGGER_SHORT_DELTA = 1;

//    public function isTransferFromSpotBeforeCheckStopsEnabled(): bool
//    {
//        return self::TRANSFER_FROM_SPOT_ON_DISTANCE >= self::CHECK_STOPS_ON_DISTANCE;
//    }
}
