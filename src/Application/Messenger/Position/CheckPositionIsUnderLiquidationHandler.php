<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position;

use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\Dto\WalletBalance;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Application\Service\Orders\StopServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\StopRepositoryInterface;
use App\Bot\Domain\Ticker;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Helper\PriceHelper;
use App\Domain\Price\Price;
use App\Domain\Price\PriceRange;
use App\Domain\Stop\StopsCollection;
use App\Domain\Value\Percent\Percent;
use App\Helper\VolumeHelper;
use App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearExchangeCacheDecoratedService;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

use function array_filter;
use function max;
use function min;

/**
 * @group liquidation
 *
 * @see \App\Tests\Functional\Application\Messenger\CheckPositionIsUnderLiquidationHandler\AddStopWhenPositionLiquidationInWarningRangeTest
 * @see \App\Tests\Unit\Application\Messenger\CheckPositionIsUnderLiquidationHandlerTest
 */
#[AsMessageHandler]
final readonly class CheckPositionIsUnderLiquidationHandler
{
    # Transfer from spot
    public const TRANSFER_FROM_SPOT_ON_DISTANCE = self::CHECK_STOPS_ON_DISTANCE / 2;
    public const TRANSFER_AMOUNT_DIFF_WITH_BALANCE = 1;
    public const MIN_TRANSFER_AMOUNT = 12;

    # To check stopped position volume
    public const CHECK_STOPS_ON_DISTANCE = 650;
    public const CHECK_STOPS_CRITICAL_DELTA_WITH_LIQUIDATION = 30;
    public const CLOSE_BY_MARKET_IF_DISTANCE_LESS_THAN = 60;

    public const ACCEPTABLE_STOPPED_PART_BEFORE_LIQUIDATION = 25;
    public const ADDITIONAL_STOP_DISTANCE_WITH_LIQUIDATION = self::CHECK_STOPS_ON_DISTANCE / 4;
    public const ADDITIONAL_STOP_TRIGGER_DELTA = 45;

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
    ) {
    }

    public function __invoke(CheckPositionIsUnderLiquidation $message): void
    {
        $acceptableStoppedPartBeforeLiquidation = self::ACCEPTABLE_STOPPED_PART_BEFORE_LIQUIDATION;
        $symbol = $message->symbol; $positionSide = $message->side;
        $position = $this->positionService->getPosition($symbol, $positionSide);
        $ticker = $this->exchangeService->ticker($symbol);
        $coin = $symbol->associatedCoin();

        if (!$position) {
            return;
        }

        if (!$position->liquidationPrice) {
            return;
        }

        $liquidation = Price::float($position->liquidationPrice);
        $priceDeltaToLiquidation = $position->priceDeltaToLiquidation($ticker);

        if ($priceDeltaToLiquidation <= self::TRANSFER_FROM_SPOT_ON_DISTANCE) {
            try {
                $spotBalance = $this->exchangeAccountService->getSpotWalletBalance($coin);
                if ($spotBalance->availableBalance > 2) {
                    $amount = $this->amountToTransferFromSpot($spotBalance, $position);
                    $this->exchangeAccountService->interTransferFromSpotToContract($coin, $amount);
//                    if (($hedge = $position->getHedge()) && $hedge->isMainPosition($position) && $hedge->getSupportRate()->part() > 0.2) return; || $acceptablePositionStopsPartBeforeLiquidation /= 2;
                }
            } catch (Throwable $e) {var_dump($e->getMessage());}
        }

        if ($priceDeltaToLiquidation <= self::CHECK_STOPS_ON_DISTANCE) {
            $volumeMustBeStopped = $position->size;

            // @todo | maybe need also check that hedge has positive distance
            if (($hedge = $position->getHedge()) && $hedge->isMainPosition($position)) {
                $volumeMustBeStopped -= $hedge->supportPosition->size;
                if ($hedge->getSupportRate()->value() > 20) {
                    $acceptableStoppedPartBeforeLiquidation = 15;
                }
            }

            $stopsBeforeLiquidationVolume = $this->getStopsVolumeBeforeLiquidation($position, $ticker);
            $stoppedPositionPart = ($stopsBeforeLiquidationVolume / $volumeMustBeStopped) * 100; // @todo | maybe need update position before calc

            $volumePartDelta = $acceptableStoppedPartBeforeLiquidation - $stoppedPositionPart;
            if ($volumePartDelta > 0) {
                if ($priceDeltaToLiquidation <= self::CLOSE_BY_MARKET_IF_DISTANCE_LESS_THAN) {
                    $this->orderService->closeByMarket($position, (new Percent($volumePartDelta))->of($position->size));
                } else {
                    $delta = self::ADDITIONAL_STOP_DISTANCE_WITH_LIQUIDATION;
                    $additionalStopPrice = $position->isShort() ? $liquidation->sub($delta) : $liquidation->add($delta);
                    $this->stopService->create(
                        $positionSide,
                        $additionalStopPrice->value(),
                        VolumeHelper::round((new Percent($volumePartDelta))->of($position->size)),
                        self::ADDITIONAL_STOP_TRIGGER_DELTA,
                    );
                }
            }
        } elseif (
            ($currentPositionPnlPercent = $ticker->indexPrice->getPnlPercentFor($position)) > 300
            && ($contractBalance = $this->exchangeAccountService->getContractWalletBalance($coin))
            && ($totalBalance = $this->exchangeAccountService->getCachedTotalBalance($symbol))
            && ($contractBalance->availableBalance / $totalBalance) > 0.5
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
            $markPriceDifferenceWithIndexPrice->isLossFor($positionSide) ? $markPriceDifferenceWithIndexPrice->delta() : 0,
        );
    }

    private function amountToTransferFromSpot(WalletBalance $spotBalance, Position $position): float
    {
        // @todo | need calc $amount based on Position size (for bigger position DEFAULT_TRANSFER_AMOUNT will change liquidationPrice very little)
        return min(self::MIN_TRANSFER_AMOUNT, PriceHelper::round($spotBalance->availableBalance - self::TRANSFER_AMOUNT_DIFF_WITH_BALANCE));
    }
}
