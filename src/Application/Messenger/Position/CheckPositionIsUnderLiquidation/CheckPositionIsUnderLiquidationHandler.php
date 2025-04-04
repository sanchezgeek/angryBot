<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position\CheckPositionIsUnderLiquidation;

use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\CheckPositionIsUnderLiquidationParams as Params;
use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\CheckPositionIsUnderLiquidationDynamicParameters as DynamicParameters;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Application\Service\Orders\StopServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\StopRepositoryInterface;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\CoinAmount;
use App\Domain\Order\ExchangeOrder;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Enum\PriceMovementDirection;
use App\Domain\Price\Price;
use App\Domain\Price\PriceMovement;
use App\Domain\Price\PriceRange;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Stop\StopsCollection;
use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearExchangeCacheDecoratedService;
use App\Worker\AppContext;
use Doctrine\ORM\QueryBuilder;
use Psr\Log\LoggerInterface;
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
 * @see \App\Tests\Unit\Application\Messenger\Position\CheckPositionIsUnderLiquidationHandlerTest
 */
#[AsMessageHandler]
final class CheckPositionIsUnderLiquidationHandler
{
    # Transfer from spot
    public const TRANSFER_FROM_SPOT_ON_DISTANCE = 200;
    public const TRANSFER_AMOUNT_DIFF_WITH_BALANCE = 1;
    public const MAX_TRANSFER_AMOUNT = 60;
    private const TRANSFER_AMOUNT_MODIFIER = 0.2;
    private const SPOT_TRANSFERS_BEFORE_ADD_STOP = 2.5;
    private const MOVE_BACK_TO_SPOT_ENABLED = false;

    public const CLOSE_BY_MARKET_IF_DISTANCE_LESS_THAN = 40;

    ### all symbols data
    /** @var Position[] */
    private array $positions;
    /** @var array<string, Price> */
    private array $lastMarkPrices;
    /** @var ActiveStopOrder[] */
    private array $activeConditionalStopOrders;

    ### each symbol runtime
    private DynamicParameters $dynamicParameters;

    public function __invoke(CheckPositionIsUnderLiquidation $message): void
    {
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

                if ($main->getHedge()?->isEquivalentHedge()) {
                    continue;
                }

                $symbol = $main->symbol;

                if (Params::isSymbolIgnored($symbol)) {
                    continue;
                }

                $messages[] = new CheckPositionIsUnderLiquidation(
                    symbol: $symbol,
                    percentOfLiquidationDistanceToAddStop: $message->percentOfLiquidationDistanceToAddStop ?? Params::getAdditionalStopDistanceWithLiquidation($symbol),
                    acceptableStoppedPart: $message->acceptableStoppedPart ?? Params::getAcceptableStoppedPart($symbol),
                    warningPnlDistance: $message->warningPnlDistance,
                );
                $this->positions[$symbol->value] = $main;
            }
        } else {
            if (!($position = $this->getPositionOld($message->symbol))) {
                return;
            }
            $symbol = $position->symbol;

            $messages = [$message];
            $this->positions[$symbol->value] = $position;
            $this->lastMarkPrices[$symbol->value] = $this->exchangeService->ticker($symbol)->markPrice;
        }

        foreach ($messages as $message) {
            try {
                $this->handleMessage($message);
            } catch (Throwable $e) {
                $this->appErrorLogger->critical(
                    sprintf('[CheckPositionIsUnderLiquidationHandler] Got error when try to handle %s: %s', $message->symbol->value, $e->getMessage())
                );
            }
        }
    }

    public function handleMessage(CheckPositionIsUnderLiquidation $message): void
    {
        $symbol = $message->symbol;
        $position = $this->positions[$symbol->value];

        $markPrice = $this->lastMarkPrices[$symbol->value];
        $ticker = new Ticker($symbol, $markPrice, $markPrice, $markPrice); // @todo Get rid of ticker?
        $coin = $symbol->associatedCoin();

        $this->dynamicParameters = new DynamicParameters($message, $position, $ticker);

        ### remove stale ###
        foreach ($this->getStaleStops($position) as $stop) {
            $this->stopRepository->remove($stop);
        }

        ### add new ###
        $distanceWithLiquidation = $position->priceDistanceWithLiquidation($ticker);
        if (
            $distanceWithLiquidation > $this->dynamicParameters->warningDistance()
            && ($lastRunMarketPrice = $this->getLastRunMarkPrice($position)) !== null
            && PriceMovement::fromToTarget($lastRunMarketPrice, $ticker->markPrice)->isProfitFor($position->side)
        ) {
            return; # skip checks if price didn't move to position loss direction AND liquidation is not in warning range
        }

//        $this->switchPositionService($ticker, $distanceWithLiquidation);

        $decreaseStopDistance = false;
        $transferFromSpotOnDistance = $this->transferFromSpotOnDistance($ticker);
        if ($distanceWithLiquidation <= $transferFromSpotOnDistance) {
            try {
                $spotBalance = $this->exchangeAccountService->getSpotWalletBalance($coin);
                if ($spotBalance->available() > 2) {
                    $amountToTransfer = FloatHelper::modify($this->getAmountToTransfer($position)->value(), self::TRANSFER_AMOUNT_MODIFIER);
                    $amountTransferred = min($amountToTransfer, $spotBalance->available->sub(self::TRANSFER_AMOUNT_DIFF_WITH_BALANCE)->value());

                    $this->exchangeAccountService->interTransferFromSpotToContract($coin, $amountTransferred);

                    $availableAfterTransfer = $spotBalance->available->sub($amountTransferred)->value();
                    if ($availableAfterTransfer / $amountToTransfer >= self::SPOT_TRANSFERS_BEFORE_ADD_STOP) {
                        return;
                    }

                    if ($amountTransferred >= $amountToTransfer) {
                        $decreaseStopDistance = true;
                    }
                }
            } catch (Throwable $e) {
                $msg = sprintf('%s: %s', OutputHelper::shortClassName(__METHOD__), $e->getMessage());
                OutputHelper::print($msg);
                $this->appErrorLogger->critical($msg, ['file' => __FILE__, 'line' => __LINE__]);
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
                $stopQty = $symbol->roundVolumeUp((new Percent($volumePartDelta))->of($notCoveredSize));

                $closeByMarketIfDistanceLessThan = PnlHelper::convertPnlPercentOnPriceToAbsDelta(self::CLOSE_BY_MARKET_IF_DISTANCE_LESS_THAN, $position->entryPrice());
                if ($distanceWithLiquidation <= $closeByMarketIfDistanceLessThan) {
                    $this->orderService->closeByMarket($position, $stopQty);
                } else {
//                    if ($decreaseStopDistance) $stopPriceDistance = $stopPriceDistance * 0.5;
                    $triggerDelta = $this->dynamicParameters->additionalStopTriggerDelta();
                    $stopPrice = $this->dynamicParameters->additionalStopPrice();

                    # Recalculate qty to min. Otherwise, created further BuyOrders can lead to excessive increase in position size
                    $exchangeOrder = ExchangeOrder::roundedToMin($symbol, $stopQty, $stopPrice);
                    $stopQty = $exchangeOrder->getVolume();

                    $context = [
                        Stop::IS_ADDITIONAL_STOP_FROM_LIQUIDATION_HANDLER => true,
                        Stop::CLOSE_BY_MARKET_CONTEXT => true, // @todo | settings
//                        Stop::FIX_HEDGE_ON_LOSS => true, // @todo | settings
                    ];

                    if (Params::isSymbolWithoutOppositeBuyOrders($symbol)) {
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

                    $this->stopService->create($position->symbol, $position->side, $stopPrice, $stopQty, $triggerDelta, $context);
                }
            }
        } elseif (
            self::MOVE_BACK_TO_SPOT_ENABLED && (
                $distanceWithLiquidation > 2000
                || ($currentPositionPnlPercent = $ticker->indexPrice->getPnlPercentFor($position)) > 300
            )
            && ($contractBalance = $this->exchangeAccountService->getContractWalletBalance($coin))
            && ($totalBalance = $this->exchangeAccountService->getCachedTotalBalance($symbol))
            && ($contractBalance->available() / $totalBalance) > 0.5
        ) {
            $this->exchangeAccountService->interTransferFromContractToSpot($coin, 1);
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

    private function findActivePositionStopOrders(Symbol $symbol, Side $positionSide, PriceRange $priceRange): array
    {
        $symbolStops = array_filter(
            $this->activeConditionalStopOrders,
            static fn (ActiveStopOrder $activeStopOrder) => $activeStopOrder->symbol === $symbol && $activeStopOrder->positionSide === $positionSide,
        );

        return array_filter($symbolStops, static function(ActiveStopOrder $order) use ($priceRange) {
            return $priceRange->isPriceInRange($order->triggerPrice);
        });
    }

    public function getAmountToTransfer(Position $position): CoinAmount
    {
        $distanceForCalcTransferAmount = $this->distanceForCalcTransferAmount !== null ? $this->distanceForCalcTransferAmount : random_int(300, 500);
        $amountCalcByDistance = $distanceForCalcTransferAmount * $position->getNotCoveredSize();

        return (new CoinAmount($position->symbol->associatedCoin(), min($amountCalcByDistance, self::MAX_TRANSFER_AMOUNT)));
    }

    private function getPositionOld(Symbol $symbol): ?Position
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

    private function transferFromSpotOnDistance(Ticker $ticker): float
    {
        return PnlHelper::convertPnlPercentOnPriceToAbsDelta(self::TRANSFER_FROM_SPOT_ON_DISTANCE, $ticker->markPrice);
    }

    private function getStaleStops(Position $position): StopsCollection
    {
        $range = $this->dynamicParameters->actualStopsRange();

        $stops = $this->stopRepository->findActive(symbol: $position->symbol, side: $position->side, qbModifier: function (QueryBuilder $qb) use ($position, $range) {
            $priceField = $qb->getRootAliases()[0] . '.price';
            $qb->andWhere(sprintf('%s > :upperPrice OR %s < :lowerPrice', $priceField, $priceField));
            $qb->setParameter(':upperPrice', $range->to()->value());
            $qb->setParameter(':lowerPrice', $range->from()->value());
        });
        # if opposite position previously was main
        $oppositePositionStops = $this->stopRepository->findActive(symbol: $position->symbol, side: $position->side->getOpposite());

        return (new StopsCollection(...$stops, ...$oppositePositionStops))->filterWithCallback(static fn (Stop $stop) => $stop->isAdditionalStopFromLiquidationHandler());
    }

    private function getLastRunMarkPrice(Position $position): ?Price
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

        $cacheItem = $this->cache->getItem(self::lastRunMarkPriceCacheKey($position))->set($ticker->markPrice)->expiresAfter(130);

        $this->cache->save($cacheItem);
    }

    private static function lastRunMarkPriceCacheKey(Position $position): string
    {
        return sprintf('liq_handler_last_run_mark_price_%s_%s', $position->symbol->value, $position->side->value);
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
        private readonly LoggerInterface $appErrorLogger,
        private readonly ?CacheInterface $cache,
        private readonly ?int $distanceForCalcTransferAmount = null,
    ) {
    }

    public static function isDebug(): bool
    {
        return AppContext::isDebug() && AppContext::isTest();
    }

//    public const ADDITIONAL_STOP_TRIGGER_DEFAULT_DELTA = 150;
//    public const ADDITIONAL_STOP_TRIGGER_MID_DELTA = 30;
//    public const ADDITIONAL_STOP_TRIGGER_SHORT_DELTA = 1;

//    public function isTransferFromSpotBeforeCheckStopsEnabled(): bool
//    {
//        return self::TRANSFER_FROM_SPOT_ON_DISTANCE >= self::CHECK_STOPS_ON_DISTANCE;
//    }
}
