<?php

declare(strict_types=1);

namespace App\Stop\Application\Handler;

use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderEntryDto;
use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderHandler;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\StopRepository;
use App\Buy\Application\Service\BaseStopLength\Processor\PredefinedStopLengthProcessor;
use App\Domain\Order\Collection\OrdersCollection;
use App\Domain\Order\Collection\OrdersLimitedWithMaxVolume;
use App\Domain\Order\Collection\OrdersWithMinExchangeVolume;
use App\Domain\Order\Order;
use App\Domain\Price\SymbolPrice;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Trading\Enum\PriceDistanceSelector;
use App\Domain\Trading\Enum\TimeFrame;
use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;
use App\Stop\Application\Contract\Command\CreateBuyOrderAfterStop;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @see \App\Tests\Functional\Modules\Stop\Applicaiton\Handler\CreateBuyOrdersAfterStopCommandHandlerTest
 */
#[AsMessageHandler]
final class CreateBuyOrderAfterStopCommandHandler
{
    public const int BIG_STOP_VOLUME_MULTIPLIER = 10;

    public const int DEFAULT_ATR_PERIOD = PredefinedStopLengthProcessor::DEFAULT_PERIOD_FOR_ATR;
    public const TimeFrame DEFAULT_ATR_TIMEFRAME = PredefinedStopLengthProcessor::DEFAULT_TIMEFRAME_FOR_ATR;

    public function __invoke(CreateBuyOrderAfterStop $command): array
    {
        $stop = $this->stopRepository->find($command->stopId);
        $isAdditionalFixationsStop = $stop->isStopAfterOtherSymbolLoss() || $stop->isStopAfterFixHedgeOppositePosition();

        if (!$stop->isWithOppositeOrder() && !$isAdditionalFixationsStop) {
            return [];
        }

        $symbol = $stop->getSymbol();
        $side = $stop->getPositionSide();
        $stopVolume = $stop->getVolume();
        $stopPrice = $symbol->makePrice($stop->getPrice()); // $price = $stop->getOriginalPrice() ?? $stop->getPrice();

        $distanceOverride = $stop->getOppositeOrderDistance();

        $isMinVolume = $stopVolume <= $symbol->minOrderQty();
        $isBigStop = FloatHelper::round($stopVolume / $command->prevPositionSize) >= 0.1;

        $refPrice = $stopPrice;
        if ($isAdditionalFixationsStop) {
            $refPrice = $symbol->makePrice($command->prevPositionEntryPrice);
        }

        $orders = [];
        if ($isMinVolume || !$isBigStop || $distanceOverride) {
            if ($distanceOverride instanceof Percent) {
                $distanceOverride = PnlHelper::convertPnlPercentOnPriceToAbsDelta($distanceOverride, $stopPrice);
            }

            $distance = $distanceOverride ?? $this->getOppositeOrderDistance($symbol, $refPrice, PriceDistanceSelector::Standard);

            $orders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume, $distance, [BuyOrder::FORCE_BUY_CONTEXT => !$isBigStop]);
        } else {
            $withForceBuy = [BuyOrder::FORCE_BUY_CONTEXT => true];

            if ($isAdditionalFixationsStop) {
                $orders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 5, PriceDistanceSelector::ModerateShort);
                $orders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 5, PriceDistanceSelector::Short);
                $orders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 3, PriceDistanceSelector::VeryShort, $withForceBuy);
                $orders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 3, 0, $withForceBuy);
            } else {
                $orders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 5, PriceDistanceSelector::VeryShort);
                $orders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 5, PriceDistanceSelector::ModerateShort, $withForceBuy);
                $orders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 3, PriceDistanceSelector::Standard);
                $orders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 5, PriceDistanceSelector::Long, $withForceBuy);
            }
        }

        $orders = new OrdersLimitedWithMaxVolume(
            new OrdersWithMinExchangeVolume($symbol, new OrdersCollection(...$orders)),
            $stopVolume
        );

        $buyOrders = [];
        foreach ($orders as $order) {
            $dto = new CreateBuyOrderEntryDto($symbol, $side, $order->volume(), $order->price()->value(), $order->context());
            $buyOrders[] = $this->createBuyOrderHandler->handle($dto)->buyOrder;
        }

        return $buyOrders;
    }

    private function orderBasedOnLengthEnum(Stop $stop, SymbolPrice $refPrice, float $volume, PriceDistanceSelector|float $length, array $additionalContext = []): Order
    {
        $commonContext = [
            BuyOrder::IS_OPPOSITE_AFTER_SL_CONTEXT => true,
            BuyOrder::ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT => $stop->getExchangeOrderId(),
            BuyOrder::OPPOSITE_SL_ID_CONTEXT => $stop->getId(),
        ];

        $side = $stop->getPositionSide();

        $distance = $length instanceof PriceDistanceSelector ? $this->getOppositeOrderDistance($stop->getSymbol(), $refPrice, $length) : $length;

        $price = $side->isShort() ? $refPrice->sub($distance) : $refPrice->add($distance);
        $volume = $stop->getSymbol()->roundVolume($volume);
        $context = array_merge($commonContext, $additionalContext);

        return new Order($price, $volume, $context);
    }

    /**
     * @param PriceDistanceSelector $lengthSelector // @todo | oppositeBuyOrder | use param from stop?
     */
    private function getOppositeOrderDistance(SymbolInterface $symbol, SymbolPrice $refPrice, PriceDistanceSelector $lengthSelector): float
    {
        return PnlHelper::convertPnlPercentOnPriceToAbsDelta(
            PnlHelper::transformPriceChangeToPnlPercent(
                $this->tradingParametersProvider->oppositeBuyLength($symbol, $lengthSelector, self::DEFAULT_ATR_TIMEFRAME, self::DEFAULT_ATR_PERIOD)
            ),
            $refPrice
        );
    }

    public function __construct(
        private readonly StopRepository $stopRepository,
        private readonly TradingParametersProviderInterface $tradingParametersProvider,
        private readonly CreateBuyOrderHandler $createBuyOrderHandler,
    ) {
    }
}
