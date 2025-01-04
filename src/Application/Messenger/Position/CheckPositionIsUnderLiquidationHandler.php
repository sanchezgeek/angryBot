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
    public const TRANSFER_FROM_SPOT_ON_DISTANCE = 100;
    public const TRANSFER_AMOUNT_DIFF_WITH_BALANCE = 1;
    public const MAX_TRANSFER_AMOUNT = 60;
    public const TRANSFER_AMOUNT_MODIFIER = 0.2;

    # Additional stop
    public const PERCENT_OF_LIQUIDATION_DISTANCE_TO_ADD_STOP_BEFORE = 50;
    public const WARNING_PNL_DISTANCE = 60;

    # To check stopped position volume
    public const ACTUAL_STOPS_RANGE_FROM_ADDITIONAL_STOP = 6;
    public const CLOSE_BY_MARKET_IF_DISTANCE_LESS_THAN = 20;

    public const ACCEPTABLE_STOPPED_PART = 7;
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

    public function __invoke(CheckPositionIsUnderLiquidation $message): void
    {
        $this->warningDistance = null;
        $this->additionalStopDistanceWithLiquidation = null;
        $this->additionalStopPrice = null;
        $this->actualStopsPriceRange = null;

        $this->handledMessage = $message;
        $symbol = $message->symbol;

        $this->ticker = $ticker = $this->exchangeService->ticker($symbol);
        $coin = $symbol->associatedCoin();

        if (!($position = $this->getPosition($symbol))) {
            return;
        }

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

        $checkStopsOnDistance = $this->checkStopsOnDistance($position, $ticker);
        if ($distanceWithLiquidation <= $checkStopsOnDistance) {
            $notCoveredSize = $position->getNotCoveredSize();
            $acceptableStoppedPart = $this->acceptableStoppedPart();
            $acceptableStoppedPartBeforeLiquidation = FloatHelper::modify($acceptableStoppedPart, self::ACCEPTABLE_STOPPED_PART_MODIFIER);
            // @todo | maybe need also check that hedge has positive distance (...&& $hedge->isProfitableHedge()...)
            if ($position->getHedge()?->getSupportRate()->value() > 25) {
                // @todo | Need to be covered with tests
                $acceptableStoppedPartBeforeLiquidation = FloatHelper::modify($acceptableStoppedPartBeforeLiquidation - $acceptableStoppedPart / 2.2, 0.05);
            }

            $stopsBeforeLiquidationVolume = $this->getStopsVolumeBeforeLiquidation($position, $ticker);
            $stoppedPositionPart = ($stopsBeforeLiquidationVolume / $notCoveredSize) * 100; // @todo | maybe need update position before calc
            $volumePartDelta = $acceptableStoppedPartBeforeLiquidation - $stoppedPositionPart;
            if ($volumePartDelta > 0) {
                $stopQty = $symbol->roundVolumeUp((new Percent($volumePartDelta))->of($notCoveredSize));

                $closeByMarketIfDistanceLessThan = FloatHelper::modify(PnlHelper::convertPnlPercentOnPriceToAbsDelta(self::CLOSE_BY_MARKET_IF_DISTANCE_LESS_THAN, $position->entryPrice()), 0.1);
                if ($distanceWithLiquidation <= $closeByMarketIfDistanceLessThan) {
                    $this->orderService->closeByMarket($position, $stopQty);
                } else {
//                    if ($decreaseStopDistance) $stopPriceDistance = $stopPriceDistance * 0.5;
                    $triggerDelta = $this->additionalStopTriggerDelta($symbol);
                    $stopPrice = $this->getAdditionalStopPrice($position);

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

        $activeConditionalStops = array_filter(
            // @todo | cache? | yes: because there are might be some problems with connection | no: because results from cache might be not actual
            $this->exchangeService->activeConditionalOrders($position->symbol, $priceRangeToFindExistedStops),
            static fn (ActiveStopOrder $activeStopOrder) => $activeStopOrder->positionSide === $positionSide,
        );

        $activeConditionalStopsVolume = 0;
        foreach ($activeConditionalStops as $activeConditionalStop) {
            $activeConditionalStopsVolume += $activeConditionalStop->volume;
        }

        return $delayedStops->totalVolume() + $activeConditionalStopsVolume;
    }

    public function getAdditionalStopPrice(Position $position): Price
    {
        if ($this->additionalStopPrice !== null) {
            return $this->additionalStopPrice;
        }

        $additionalStopDistanceWithLiquidation = $this->additionalStopDistanceWithLiquidation($position);

        return $this->additionalStopPrice = (
            $position->isShort()
                ? $position->liquidationPrice()->sub($additionalStopDistanceWithLiquidation)
                : $position->liquidationPrice()->add($additionalStopDistanceWithLiquidation)
        );
    }

    public function acceptableStoppedPart(): int
    {
        return $this->handledMessage->acceptableStoppedPart ?? self::ACCEPTABLE_STOPPED_PART;
    }

    public function getAmountToTransfer(Position $position): CoinAmount
    {
        $distanceForCalcTransferAmount = $this->distanceForCalcTransferAmount !== null ? $this->distanceForCalcTransferAmount : random_int(300, 500);
        $amountCalcByDistance = $distanceForCalcTransferAmount * $position->getNotCoveredSize();

        return (new CoinAmount($position->symbol->associatedCoin(), min($amountCalcByDistance, self::MAX_TRANSFER_AMOUNT)));
    }

    private function getPosition(Symbol $symbol): ?Position
    {
        if (!($positions = $this->selectedPositionService->getPositions($symbol))) {
            return null;
        }

        $position = $positions[0]->getHedge()?->mainPosition ?? $positions[0];
        if (!$position->liquidationPrice) {
            return null;
        }

        return $position;
    }

    private function transferFromSpotOnDistance(Ticker $ticker): float
    {
        return FloatHelper::modify(PnlHelper::convertPnlPercentOnPriceToAbsDelta(self::TRANSFER_FROM_SPOT_ON_DISTANCE, $ticker->indexPrice), 0.1);
    }

    private function warningDistance(): float
    {
        if ($this->warningDistance === null) {
            $this->warningDistance = FloatHelper::modify(PnlHelper::convertPnlPercentOnPriceToAbsDelta(self::WARNING_PNL_DISTANCE, $this->ticker->indexPrice), 0.1);
        }

        return $this->warningDistance;
    }

    private function checkStopsOnDistance(Position $position, Ticker $ticker): float
    {
        $message = $this->handledMessage;
        if ($message->checkStopsOnPnlPercent !== null) {
            return FloatHelper::modify(PnlHelper::convertPnlPercentOnPriceToAbsDelta($message->checkStopsOnPnlPercent, $ticker->indexPrice), 0.1);
        }

        return $this->additionalStopDistanceWithLiquidation($position) * 1.5;
    }

    private function additionalStopDistanceWithLiquidation(Position $position): float
    {
        if ($this->additionalStopDistanceWithLiquidation === null) {
            $distancePnl = $this->handledMessage->percentOfLiquidationDistanceToAddStop ?? self::PERCENT_OF_LIQUIDATION_DISTANCE_TO_ADD_STOP_BEFORE;
            $this->additionalStopDistanceWithLiquidation = FloatHelper::modify((new Percent($distancePnl))->of($position->liquidationDistance()), 0.15, 0.05);

            $this->additionalStopDistanceWithLiquidation = max($this->additionalStopDistanceWithLiquidation, $this->warningDistance());
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

        return (new StopsCollection(...$stops))->filterWithCallback(static fn (Stop $stop) => $stop->isAdditionalStopFromLiquidationHandler());
    }

    public function getActualStopsRange(Position $position): PriceRange
    {
        if ($this->actualStopsPriceRange !== null) {
            return $this->actualStopsPriceRange;
        }

        $additionalStopPrice = $this->getAdditionalStopPrice($position);
        $modifier = FloatHelper::modify((new Percent(self::ACTUAL_STOPS_RANGE_FROM_ADDITIONAL_STOP))->of($position->liquidationDistance()), 0.1);

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

        $cacheItem = $this->cache->getItem(self::lastRunMarkPriceCacheKey($position))->set($ticker->markPrice)->expiresAfter(300);

        $this->cache->save($cacheItem);
    }

    private static function lastRunMarkPriceCacheKey(Position $position): string
    {
        return sprintf('liq_handler_last_run_mark_price_%s_%s', $position->symbol->name, $position->side->value);
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
