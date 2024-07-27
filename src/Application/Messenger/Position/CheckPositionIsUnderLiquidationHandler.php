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
use App\Domain\Coin\CoinAmount;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Price;
use App\Domain\Price\PriceRange;
use App\Domain\Stop\StopsCollection;
use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;
use App\Helper\VolumeHelper;
use App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearExchangeCacheDecoratedService;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

use function array_filter;
use function max;
use function min;
use function random_int;

/**
 * @group liquidation
 *
 * @see \App\Tests\Functional\Application\Messenger\Position\CheckPositionIsUnderLiquidationHandler\AddStopWhenPositionLiquidationInWarningRangeTest
 * @see \App\Tests\Unit\Application\Messenger\Position\CheckPositionIsUnderLiquidationHandlerTest
 */
#[AsMessageHandler]
final readonly class CheckPositionIsUnderLiquidationHandler
{
    # Transfer from spot
    public const TRANSFER_FROM_SPOT_ON_DISTANCE = self::CHECK_STOPS_ON_DISTANCE;
    public const TRANSFER_AMOUNT_DIFF_WITH_BALANCE = 1;
    public const MAX_TRANSFER_AMOUNT = 60;
    public const TRANSFER_AMOUNT_MODIFIER = 0.2;

    # To check stopped position volume
    public const CHECK_STOPS_ON_DISTANCE = 750;
    public const CHECK_STOPS_CRITICAL_DELTA_WITH_LIQUIDATION = 20;
    public const CLOSE_BY_MARKET_IF_DISTANCE_LESS_THAN = 60;

    public const ACCEPTABLE_STOPPED_PART = 18;              private const ACCEPTABLE_STOPPED_PART_MODIFIER = 0.2;

    public const ADDITIONAL_STOP_DISTANCE_WITH_LIQUIDATION = self::CHECK_STOPS_ON_DISTANCE / 5.5;
    public const ADDITIONAL_STOP_TRIGGER_DEFAULT_DELTA = 150;
    public const ADDITIONAL_STOP_TRIGGER_MID_DELTA = 30;
    public const ADDITIONAL_STOP_TRIGGER_SHORT_DELTA = 1;

    const SPOT_TRANSFERS_BEFORE_ADD_STOP = 2.5;
    const MOVE_BACK_TO_SPOT_ENABLED = false;

    /**
     * @param ByBitLinearExchangeCacheDecoratedService $exchangeService
     */
    public function __construct(
        private ExchangeServiceInterface $exchangeService,
        private PositionServiceInterface $positionService,
        private ExchangeAccountServiceInterface $exchangeAccountService,
        private OrderServiceInterface $orderService,
        private StopServiceInterface $stopService,
        private StopRepositoryInterface $stopRepository,
        private ?int $distanceForCalcTransferAmount = null
    ) {
    }

    public function isTransferFromSpotBeforeCheckStopsEnabled(): bool
    {
        return self::TRANSFER_FROM_SPOT_ON_DISTANCE >= self::CHECK_STOPS_ON_DISTANCE;
    }

    public function __invoke(CheckPositionIsUnderLiquidation $message): void
    {
        $symbol = $message->symbol;
        if (!($positions = $this->positionService->getPositions($symbol))) {
            return;
        }
        $hedge = $positions[0]->getHedge();
        $position = $hedge ? $hedge->mainPosition : $positions[0];
        $positionSide = $position->side;

        $ticker = $this->exchangeService->ticker($symbol);
        $coin = $symbol->associatedCoin();

        if (!$position->liquidationPrice) {
            return;
        }

        $liquidation = Price::float($position->liquidationPrice);
        $distanceWithLiquidation = $position->priceDistanceWithLiquidation($ticker);
        $acceptableStoppedPartBeforeLiquidation = FloatHelper::modify(self::ACCEPTABLE_STOPPED_PART, self::ACCEPTABLE_STOPPED_PART_MODIFIER);

        $decreaseStopDistance = false;
        $transferFromSpotOnDistance = FloatHelper::modify(self::TRANSFER_FROM_SPOT_ON_DISTANCE, 0.1);
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
            } catch (Throwable $e) {var_dump($e->getMessage());}
        }

        $checkStopsOnDistance = FloatHelper::modify(self::CHECK_STOPS_ON_DISTANCE, 0.1);
        if ($distanceWithLiquidation <= $checkStopsOnDistance) {
            $volumeMustBeStopped = $position->size;

            // @todo | maybe need also check that hedge has positive distance (...&& $hedge->isProfitableHedge()...)
            if ($hedge) {
                // replace with existed method? + tests
                $volumeMustBeStopped -= $hedge->supportPosition->size;
                if ($hedge->getSupportRate()->value() > 25) {
                    $acceptableStoppedPartBeforeLiquidation = FloatHelper::modify($acceptableStoppedPartBeforeLiquidation - self::ACCEPTABLE_STOPPED_PART / 2.2, 0.05);
                }
            }

            $stopsBeforeLiquidationVolume = $this->getStopsVolumeBeforeLiquidation($position, $ticker);
            $stoppedPositionPart = ($stopsBeforeLiquidationVolume / $volumeMustBeStopped) * 100; // @todo | maybe need update position before calc
            $volumePartDelta = $acceptableStoppedPartBeforeLiquidation - $stoppedPositionPart;
            if ($volumePartDelta > 0) {
                $stopQty = VolumeHelper::round((new Percent($volumePartDelta))->of($position->size));

                if ($distanceWithLiquidation <= self::CLOSE_BY_MARKET_IF_DISTANCE_LESS_THAN) {
                    $this->orderService->closeByMarket($position, $stopQty);
                } else {
                    $stopPriceDistance = self::ADDITIONAL_STOP_DISTANCE_WITH_LIQUIDATION;
                    $triggerDelta = FloatHelper::modify(self::ADDITIONAL_STOP_TRIGGER_DEFAULT_DELTA, 0.1);
                    if ($stopPriceDistance > 500) {
                        $triggerDelta = self::ADDITIONAL_STOP_TRIGGER_SHORT_DELTA;
                    } elseif ($decreaseStopDistance) {
                        $triggerDelta = FloatHelper::modify(self::ADDITIONAL_STOP_TRIGGER_MID_DELTA, 0.1);
                        $stopPriceDistance = $stopPriceDistance * 0.5;
                    }

                    $stopPriceDistance = FloatHelper::modify($stopPriceDistance, 0.15, 0.05);
                    $stopPrice = $position->isShort() ? $liquidation->sub($stopPriceDistance) : $liquidation->add($stopPriceDistance);
                    $this->stopService->create($positionSide, $stopPrice, $stopQty, $triggerDelta);
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
    }

    private function getStopsVolumeBeforeLiquidation(Position $position, Ticker $ticker): float
    {
        $positionSide = $position->side;
        $liquidation = Price::float($position->liquidationPrice);
        $criticalDeltaBeforeLiquidation = $this->getStopCriticalDeltaBeforeLiquidation($ticker, $positionSide);

        $indexPrice = $ticker->indexPrice; $markPrice = $ticker->markPrice;

        $priceRangeToFindExistedStops = PriceRange::create(
            $position->isShort() ? $liquidation->sub($criticalDeltaBeforeLiquidation) : $liquidation->add($criticalDeltaBeforeLiquidation),
            $position->isShort() ? min($indexPrice->value(), $markPrice->value()) : max($indexPrice->value(), $markPrice->value()),
        );

        $delayedStops = $this->stopRepository->findActive(side: $positionSide, qbModifier: function (QueryBuilder $qb) use ($priceRangeToFindExistedStops) {
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

    public function getStopCriticalDeltaBeforeLiquidation(Ticker $ticker, Side $positionSide): float
    {
        $markPriceDifferenceWithIndexPrice = $ticker->markPrice->differenceWith($ticker->indexPrice);

        return max(
            self::CHECK_STOPS_CRITICAL_DELTA_WITH_LIQUIDATION,
            $markPriceDifferenceWithIndexPrice->isLossFor($positionSide) ? $markPriceDifferenceWithIndexPrice->absDelta() : 0,
        );
    }

    public function getAmountToTransfer(Position $position): CoinAmount
    {
        $distanceForCalcTransferAmount = $this->distanceForCalcTransferAmount !== null ? $this->distanceForCalcTransferAmount : random_int(300, 500);
        $amountCalcByDistance = $distanceForCalcTransferAmount * $position->getNotCoveredSize();

        return (new CoinAmount($position->symbol->associatedCoin(), min($amountCalcByDistance, self::MAX_TRANSFER_AMOUNT)));
    }
}
