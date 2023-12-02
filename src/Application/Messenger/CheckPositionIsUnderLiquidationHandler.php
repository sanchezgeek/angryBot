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

use function abs;
use function array_filter;
use function max;

/** @see CheckPositionIsUnderLiquidationHandlerTest */
#[AsMessageHandler]
final readonly class CheckPositionIsUnderLiquidationHandler
{
    public const WARNING_LIQUIDATION_DELTA = 90;
    public const CRITICAL_LIQUIDATION_DELTA = 30;

    public const DEFAULT_COIN_TRANSFER_AMOUNT = 15;

    public const MIN_STOPS_POSITION_PART_IN_CRITICAL_RANGE = 20;
    public const MIN_STOP_DELTA_WITH_LIQUIDATION = 30;
    public const STOP_TRIGGER_DELTA = 40;
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
        $priceRange = PriceRange::create($position->liquidationPrice, $ticker->indexPrice);
        $positionSide = $position->side;

        $stops = $this->stopRepository->findActive(
            side: $positionSide,
            qbModifier: function (QueryBuilder $qb) use ($priceRange) {
                $priceField = $qb->getRootAliases()[0] . '.price';

                $qb
                    ->andWhere($priceField . ' BETWEEN :priceFrom AND :priceTo')
                    ->setParameter(':priceFrom', $priceRange->from()->value())
                    ->setParameter(':priceTo', $priceRange->to()->value())
                ;
            }
        );

        /** @todo | переделать isPriceInRange? */
        $stops = new StopsCollection(...$stops);
        $stops = $stops->filterWithCallback(static fn (Stop $stop) => !$stop->isTakeProfitOrder());

        $delayedStopsVolumePart = $stops->volumePart($position->size);

        $activeConditionalStops = array_filter(
            $this->exchangeService->activeConditionalOrders($position->symbol, $priceRange),
            static fn(ActiveStopOrder $activeStopOrder) => $activeStopOrder->positionSide === $positionSide,
        );

        $activeConditionalStopsVolumeSum = 0;
        foreach ($activeConditionalStops as $activeConditionalStop) {
            $activeConditionalStopsVolumeSum += $activeConditionalStop->volume;
        }
        $activeConditionalStopsVolumePart = VolumeHelper::round(($activeConditionalStopsVolumeSum / $position->size) * 100);

        $totalVolumePart = $delayedStopsVolumePart + $activeConditionalStopsVolumePart;

        $volumePartDelta = self::MIN_STOPS_POSITION_PART_IN_CRITICAL_RANGE - $totalVolumePart;
        if ($volumePartDelta > 0) {
            $stopDeltaWithLiquidation = max(
                self::MIN_STOP_DELTA_WITH_LIQUIDATION,
                $ticker->isLastPriceOverIndexPrice($positionSide) ? abs($ticker->lastPrice->value() - $ticker->indexPrice) : 0
            );

            $price = Price::float($position->liquidationPrice);
            $price = $position->isShort() ? $price->sub($stopDeltaWithLiquidation) : $price->add($stopDeltaWithLiquidation);
            $this->stopService->create(
                $positionSide,
                $price->value(),
                VolumeHelper::round((new Percent($volumePartDelta))->of($position->size)),
                self::STOP_TRIGGER_DELTA,
            );
        }
    }
}
