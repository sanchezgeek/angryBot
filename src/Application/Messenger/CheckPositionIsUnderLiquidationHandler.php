<?php

declare(strict_types=1);

namespace App\Application\Messenger;

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
use App\Domain\Price\Helper\PriceHelper;
use App\Domain\Price\Price;
use App\Domain\Price\PriceRange;
use App\Domain\Stop\StopsCollection;
use App\Domain\Value\Percent\Percent;
use App\Helper\VolumeHelper;
use App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearExchangeCacheDecoratedService;
use App\Tests\Unit\Application\Messenger\CheckPositionIsUnderLiquidationHandlerTest;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function array_filter;
use function max;

/** @see CheckPositionIsUnderLiquidationHandlerTest */
#[AsMessageHandler]
final readonly class CheckPositionIsUnderLiquidationHandler
{
    public const WARNING_LIQUIDATION_DELTA = 90;
    public const CRITICAL_LIQUIDATION_DELTA = 30;

    # for warning actions
    public const ACCEPTABLE_POSITION_STOPS_PART_BEFORE_CRITICAL_RANGE = 22;
    public const ADDITIONAL_STOP_TRIGGER_DELTA = 40;
    public const ADDITIONAL_STOP_MIN_DELTA_WITH_POSITION_LIQUIDATION = self::CRITICAL_LIQUIDATION_DELTA + 5;

    # for critical actions
    public const DEFAULT_COIN_TRANSFER_AMOUNT = 15;
    public const CLOSE_BY_MARKET_PERCENT = '8%';

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
        $symbol = $message->symbol;
        $positionSide = $message->side;

        $position = $this->positionService->getPosition($symbol, $positionSide);
        $ticker = $this->exchangeService->ticker($symbol);

        if (!$position) {
            return;
        }

        $priceDeltaToLiquidation = $position->priceDeltaToLiquidation($ticker);

        if ($priceDeltaToLiquidation <= self::CRITICAL_LIQUIDATION_DELTA) {
            $this->orderService->closeByMarket($position, Percent::string(self::CLOSE_BY_MARKET_PERCENT)->of($position->size));

            $spotBalance = $this->exchangeAccountService->getSpotWalletBalance($coin = $symbol->associatedCoin());
            if ($spotBalance->availableBalance > 0.2) {
                $amount = min(self::DEFAULT_COIN_TRANSFER_AMOUNT, PriceHelper::round($spotBalance->availableBalance - 0.1));
                $this->exchangeAccountService->interTransferFromSpotToContract($coin, $amount);
            }
        }

        if ($priceDeltaToLiquidation <= self::WARNING_LIQUIDATION_DELTA) {
            $this->checkStopsVolume($position, $ticker);
        }
    }

    private function checkStopsVolume(Position $position, Ticker $ticker): void
    {
        $positionSide = $position->side;
        $liquidation = Price::float($position->liquidationPrice);

        $indexPrice = Price::float($ticker->indexPrice);
        $markPrice = $ticker->markPrice;

        $uppedBoundToCheckExistedStops = $position->isShort() ? $liquidation->sub(self::CRITICAL_LIQUIDATION_DELTA) : $liquidation->add(self::CRITICAL_LIQUIDATION_DELTA);
        $lowerBoundToCheckExistedStops = $position->isShort() ? min($indexPrice->value(), $markPrice->value()) : max($indexPrice->value(), $markPrice->value());
        $priceRangeToFindExistedStops = PriceRange::create($uppedBoundToCheckExistedStops, $lowerBoundToCheckExistedStops);

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

        $totalStopsVolume = $delayedStops->totalVolume() + $activeConditionalStopsVolume;
        $totalStopsVolumePart = round(($totalStopsVolume / $position->size) * 100, 3);

        $volumePartDelta = self::ACCEPTABLE_POSITION_STOPS_PART_BEFORE_CRITICAL_RANGE - $totalStopsVolumePart;
        if ($volumePartDelta > 0) {
            $markPriceDifferenceWithIndexPrice = $markPrice->differenceWith($indexPrice);

            $additionalStopDeltaWithLiquidation = max(
                self::ADDITIONAL_STOP_MIN_DELTA_WITH_POSITION_LIQUIDATION,
                $markPriceDifferenceWithIndexPrice->isLossFor($positionSide) ? $markPriceDifferenceWithIndexPrice->delta() : 0
            );

            $additionalStopPrice = $position->isShort() ? $liquidation->sub($additionalStopDeltaWithLiquidation) : $liquidation->add($additionalStopDeltaWithLiquidation);
            $this->stopService->create(
                $positionSide,
                $additionalStopPrice->value(),
                VolumeHelper::round((new Percent($volumePartDelta))->of($position->size)),
                self::ADDITIONAL_STOP_TRIGGER_DELTA,
            );
        }
    }
}
