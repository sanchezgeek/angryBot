<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position;

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
use App\Messenger\SchedulerTransport\SchedulerFactory;
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
    public const TRANSFER_AMOUNT_MODIFIER = 0.2;

    # Additional stop
    public const PERCENT_OF_LIQUIDATION_DISTANCE_TO_ADD_STOP_BEFORE = 90;
    public const WARNING_PNL_DISTANCES = [
        Symbol::BTCUSDT->value => 120,
        Symbol::ETHUSDT->value => 250,
    ];
    public const WARNING_PNL_DISTANCE_DEFAULT = 400;

    # To check stopped position volume
    public const ACTUAL_STOPS_RANGE_FROM_ADDITIONAL_STOP = 10;
    public const CLOSE_BY_MARKET_IF_DISTANCE_LESS_THAN = 40;

    public const ACCEPTABLE_STOPPED_PART = 5;
    private const ACCEPTABLE_STOPPED_PART_MODIFIER = 0.2;

    const SPOT_TRANSFERS_BEFORE_ADD_STOP = 2.5;
    const MOVE_BACK_TO_SPOT_ENABLED = false;

    private PositionServiceInterface $selectedPositionService;

    private ?float $warningDistance = null;
    private ?float $additionalStopDistanceWithLiquidation = null;
    private ?Price $additionalStopPrice = null;
    private ?PriceRange $actualStopsPriceRange = null;

    private ?CheckPositionIsUnderLiquidation $handledMessage = null;
    private ?Ticker $ticker = null;
    private ?Position $position = null;

    public static function isDebug(): bool
    {
        return AppContext::isDebug() && AppContext::isTest();
    }

    /** @var Position[] */
    private array $positions;

    /** @var array<string, Price> */
    private array $lastMarkPrices;

    public function __invoke(CheckPositionIsUnderLiquidation $message): void
    {
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
                $messages[] = new CheckPositionIsUnderLiquidation(
                    symbol: $symbol,
                    percentOfLiquidationDistanceToAddStop: $message->percentOfLiquidationDistanceToAddStop ?? SchedulerFactory::getAdditionalStopDistanceWithLiquidation($symbol),
                    acceptableStoppedPart: $message->acceptableStoppedPart ?? SchedulerFactory::getAcceptableStoppedPart($symbol),
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
            $this->handleMessage($message);
        }
    }

    public function handleMessage(CheckPositionIsUnderLiquidation $message): void
    {
        $this->handledMessage = $message;

        $this->warningDistance = null;
        $this->additionalStopDistanceWithLiquidation = null;
        $this->additionalStopPrice = null;
        $this->actualStopsPriceRange = null;
        $this->ticker = null;

        $symbol = $message->symbol;
        $this->position = $position = $this->positions[$symbol->value];

        $markPrice = $this->lastMarkPrices[$symbol->value];
        $this->ticker = $ticker = new Ticker($symbol, $markPrice, $markPrice, $markPrice);
        $coin = $symbol->associatedCoin();

        ### remove stale ###
        foreach ($this->getStaleStops($position) as $stop) {
            $this->stopRepository->remove($stop);
        }

        ### add new ###
        $distanceWithLiquidation = $position->priceDistanceWithLiquidation($ticker);
        if (
            $distanceWithLiquidation > $this->warningDistance()
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

        $checkStopsOnDistance = $this->checkStopsOnDistance();
        if ($distanceWithLiquidation <= $checkStopsOnDistance) {
            $notCoveredSize = $position->getNotCoveredSize();
            $acceptableStoppedPart = $this->acceptableStoppedPart();
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
                    $triggerDelta = $this->additionalStopTriggerDelta($symbol);
                    $stopPrice = $this->getAdditionalStopPrice();

                    $context = [
                        Stop::IS_ADDITIONAL_STOP_FROM_LIQUIDATION_HANDLER => true,
                        Stop::CLOSE_BY_MARKET_CONTEXT => true, // @todo | settings
                    ];
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
        $actualStopsRange = $this->getActualStopsRange($position);

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

        if (AppContext::isTest()) {
            $activeConditionalStops = array_filter(
                // @todo | cache? | yes: because there are might be some problems with connection | no: because results from cache might be not actual
                $this->exchangeService->activeConditionalOrders($position->symbol, $priceRangeToFindExistedStops),
                static fn (ActiveStopOrder $activeStopOrder) => $activeStopOrder->positionSide === $positionSide,
            );
        } else {
            // @todo | Fetch orders for calc.
            $activeConditionalStops = [];
        }

        $activeConditionalStopsVolume = 0;
        foreach ($activeConditionalStops as $activeConditionalStop) {
            $activeConditionalStopsVolume += $activeConditionalStop->volume;
        }

        return $delayedStops->totalVolume() + $activeConditionalStopsVolume;
    }

    public function getAdditionalStopPrice(): Price
    {
        if ($this->additionalStopPrice !== null) {
            return $this->additionalStopPrice;
        }

        $position = $this->position;
        $stopDistanceWithLiquidation = $this->additionalStopDistanceWithLiquidation(true);

        return $this->additionalStopPrice = (
            $position->isShort()
                ? $position->liquidationPrice()->sub($stopDistanceWithLiquidation)
                : $position->liquidationPrice()->add($stopDistanceWithLiquidation)
        );
    }

    public function acceptableStoppedPart(): float
    {
        if ($this->handledMessage->acceptableStoppedPart) {
            return $this->handledMessage->acceptableStoppedPart;
        }

        $ticker = $this->ticker;
        $position = $this->position;
        $distanceWithLiquidation = $position->priceDistanceWithLiquidation($ticker);
//            if (!$this->position->isLiquidationPlacedBeforeEntry()) { # normal situation} else { # bad scenario}
        if ($position->isPositionInLoss($ticker->markPrice)) {
            $additionalStopDistanceWithLiquidation = $this->additionalStopDistanceWithLiquidation(true);
            $initialDistanceWithLiquidation = $position->liquidationDistance();
            $distanceLeftInPercent = Percent::fromPart($additionalStopDistanceWithLiquidation / $initialDistanceWithLiquidation)->value();
            $acceptableStoppedPart = 100 - $distanceLeftInPercent;

            $priceToCalcModifier = $position->liquidationPrice()->modifyByDirection($position->side, PriceMovementDirection::TO_PROFIT, $additionalStopDistanceWithLiquidation);
            $currentDistanceWithLiquidationInPercentOfTickerPrice = PnlHelper::convertAbsDeltaToPnlPercentOnPrice($additionalStopDistanceWithLiquidation, $priceToCalcModifier)->value();
            $modifier = (100 / $currentDistanceWithLiquidationInPercentOfTickerPrice) * 7;
            if ($modifier > 1) {
                $modifier = 1;
            }

            return ($acceptableStoppedPart / 3) * $modifier;
//            return ($acceptableStoppedPart / 1.5) * $modifier;
        } elseif ($distanceWithLiquidation <= $this->warningDistance()) {
            $additionalStopDistanceWithLiquidation = $position->priceDistanceWithLiquidation($ticker);
            $initialDistanceWithLiquidation = $this->warningDistance();
            $distanceLeftInPercent = Percent::fromPart($additionalStopDistanceWithLiquidation / $initialDistanceWithLiquidation)->value();
            $acceptableStoppedPart = 100 - $distanceLeftInPercent;

            $currentDistanceWithLiquidationInPercentOfTickerPrice = PnlHelper::convertAbsDeltaToPnlPercentOnPrice($additionalStopDistanceWithLiquidation, $ticker->markPrice)->value();
            $modifier = (100 / $currentDistanceWithLiquidationInPercentOfTickerPrice) * 7;
            if ($modifier > 1) {
                $modifier = 1;
            }

            return $acceptableStoppedPart * $modifier;
        }

        return self::ACCEPTABLE_STOPPED_PART;
    }

    public function getAmountToTransfer(Position $position): CoinAmount
    {
        $distanceForCalcTransferAmount = $this->distanceForCalcTransferAmount !== null ? $this->distanceForCalcTransferAmount : random_int(300, 500);
        $amountCalcByDistance = $distanceForCalcTransferAmount * $position->getNotCoveredSize();

        return (new CoinAmount($position->symbol->associatedCoin(), min($amountCalcByDistance, self::MAX_TRANSFER_AMOUNT)));
    }

    private function getPositionOld(Symbol $symbol): ?Position
    {
        if (!($positions = $this->selectedPositionService->getPositions($symbol))) {
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
        return PnlHelper::convertPnlPercentOnPriceToAbsDelta(self::TRANSFER_FROM_SPOT_ON_DISTANCE, $ticker->indexPrice);
    }

    private function warningDistance(): float
    {
        if ($this->handledMessage->warningPnlDistance) {
            $distance = $this->handledMessage->warningPnlDistance;
        } else {
            $symbol = $this->handledMessage->symbol;
            $distance = self::WARNING_PNL_DISTANCES[$symbol->value] ?? self::WARNING_PNL_DISTANCE_DEFAULT;
        }

        // @todo | calc must be based on $this->ticker->indexPrice
        $priceToCalcAbsoluteDistance = $this->position->entryPrice();

        if ($this->warningDistance === null) {
            if (!$this->position->isLiquidationPlacedBeforeEntry()) { # normal scenario
                $warningDistance = PnlHelper::convertPnlPercentOnPriceToAbsDelta($distance, $priceToCalcAbsoluteDistance);
                $this->warningDistance = max($warningDistance, (new Percent(30))->of($this->position->liquidationDistance()));
            } else { # bad scenario
                $warningDistance = PnlHelper::convertPnlPercentOnPriceToAbsDelta($distance, $priceToCalcAbsoluteDistance);
                $this->warningDistance = $warningDistance;
            }
        }

        return $this->warningDistance;
    }

    private function checkStopsOnDistance(): float
    {
        $message = $this->handledMessage;
        $ticker = $this->ticker;

        if ($message->checkStopsOnPnlPercent !== null) {
            return PnlHelper::convertPnlPercentOnPriceToAbsDelta($message->checkStopsOnPnlPercent, $ticker->markPrice);
        }

        return $ticker->symbol->makePrice($this->additionalStopDistanceWithLiquidation() * 1.5)->value();
    }

    private function additionalStopDistanceWithLiquidation(bool $minWithTickerDistance = false): float
    {
        $position = $this->position;

        if ($this->additionalStopDistanceWithLiquidation === null) {
            if (!$this->position->isLiquidationPlacedBeforeEntry()) { # normal situation
                $distancePnl = $this->handledMessage->percentOfLiquidationDistanceToAddStop ?? self::PERCENT_OF_LIQUIDATION_DISTANCE_TO_ADD_STOP_BEFORE;
                $this->additionalStopDistanceWithLiquidation = (new Percent($distancePnl, false))->of($position->liquidationDistance());

                $this->additionalStopDistanceWithLiquidation = max($this->additionalStopDistanceWithLiquidation, $this->warningDistance());
            } else { # bad scenario
                // in this case using big position liquidationDistance may lead to add unnecessary stops
                // so just use some "warningDistance"
                $this->additionalStopDistanceWithLiquidation = $this->warningDistance();
            }

            if ($minWithTickerDistance) {
                $this->additionalStopDistanceWithLiquidation = min(
                    $position->priceDistanceWithLiquidation($this->ticker),
                    $this->additionalStopDistanceWithLiquidation
                );
            }
        }

        return $this->additionalStopDistanceWithLiquidation;
    }

    private function additionalStopTriggerDelta(Symbol $symbol): float
    {
        return FloatHelper::modify($symbol->stopDefaultTriggerDelta() * 3, 0.1);
    }

    private function getStaleStops(Position $position): StopsCollection
    {
        $range = $this->getActualStopsRange($position);

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

    public function getActualStopsRange(Position $position): PriceRange
    {
        if ($this->actualStopsPriceRange !== null) {
            return $this->actualStopsPriceRange;
        }

        $additionalStopPrice = $this->getAdditionalStopPrice();
        $modifier = (new Percent(self::ACTUAL_STOPS_RANGE_FROM_ADDITIONAL_STOP))->of($position->liquidationDistance());

        return $this->actualStopsPriceRange = PriceRange::create($additionalStopPrice->sub($modifier), $additionalStopPrice->add($modifier));

// @todo | mb $markPriceDifferenceWithIndexPrice?
//        $markPriceDifferenceWithIndexPrice = $ticker->markPrice->differenceWith($ticker->indexPrice);
//        return max(
//            $checkStopsCriticalDeltaWithLiquidation,
//            $markPriceDifferenceWithIndexPrice->isLossFor($position->side) ? $markPriceDifferenceWithIndexPrice->absDelta() : 0,
//        );
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
        private readonly PositionServiceInterface $cachedPositionService,
        private readonly PositionServiceInterface $positionService,
        private readonly ExchangeAccountServiceInterface $exchangeAccountService,
        private readonly OrderServiceInterface $orderService,
        private readonly StopServiceInterface $stopService,
        private readonly StopRepositoryInterface $stopRepository,
        private readonly LoggerInterface $appErrorLogger,
        private readonly ?CacheInterface $cache,
        private readonly ?int $distanceForCalcTransferAmount = null,
    ) {
        $this->selectedPositionService = $this->positionService;
    }

    private function switchPositionService(Ticker $currentTicker, float $distanceWithLiquidation): void
    {
        $safeDistance = PnlHelper::convertPnlPercentOnPriceToAbsDelta(228.229, $currentTicker->markPrice);

        if ($distanceWithLiquidation > $safeDistance) {
            $this->selectedPositionService = $this->cachedPositionService;
        } else {
            $this->selectedPositionService = $this->positionService;
        }
    }

//    public const ADDITIONAL_STOP_TRIGGER_DEFAULT_DELTA = 150;
//    public const ADDITIONAL_STOP_TRIGGER_MID_DELTA = 30;
//    public const ADDITIONAL_STOP_TRIGGER_SHORT_DELTA = 1;

//    public function isTransferFromSpotBeforeCheckStopsEnabled(): bool
//    {
//        return self::TRANSFER_FROM_SPOT_ON_DISTANCE >= self::CHECK_STOPS_ON_DISTANCE;
//    }
}
