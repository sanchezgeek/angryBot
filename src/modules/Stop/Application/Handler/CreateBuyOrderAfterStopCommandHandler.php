<?php

declare(strict_types=1);

namespace App\Stop\Application\Handler;

use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderEntryDto;
use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderHandler;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
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
use App\Domain\Trading\Enum\PredefinedStopLengthSelector;
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
        if (!(
            $stop->isWithOppositeOrder())
            || $stop->isStopAfterOtherSymbolLoss()
        ) {
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
        if ($stop->isStopAfterOtherSymbolLoss()) {
            $position = $this->positionService->getPosition($symbol, $side);
            $refPrice = $position->entryPrice();
        }

        $orders = [];
        if ($isMinVolume || !$isBigStop || $distanceOverride) {
            if ($distanceOverride instanceof Percent) {
                $distanceOverride = PnlHelper::convertPnlPercentOnPriceToAbsDelta($distanceOverride, $stopPrice);
            }

            $distance = $distanceOverride ?? $this->getOppositeOrderDistance($symbol, $refPrice, PredefinedStopLengthSelector::Standard);

            $orders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume, $distance, [BuyOrder::FORCE_BUY_CONTEXT => !$isBigStop]);
        } else {
            $withForceBuy = [BuyOrder::FORCE_BUY_CONTEXT => true];

            if ($stop->isStopAfterOtherSymbolLoss()) {
                $orders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 3, 0, $withForceBuy);
                $orders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 3, PredefinedStopLengthSelector::VeryShort, $withForceBuy);
                $orders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 5, PredefinedStopLengthSelector::ModerateShort);
            } else {
                $orders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 5, PredefinedStopLengthSelector::VeryShort);
                $orders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 5, PredefinedStopLengthSelector::ModerateShort, $withForceBuy);
                $orders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 3, PredefinedStopLengthSelector::Standard);
                $orders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 5, PredefinedStopLengthSelector::Long, $withForceBuy);
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

    private function orderBasedOnLengthEnum(Stop $stop, SymbolPrice $refPrice, float $volume, PredefinedStopLengthSelector|float $length, array $additionalContext = []): Order
    {
        $commonContext = [
            BuyOrder::IS_OPPOSITE_AFTER_SL_CONTEXT => true,
            BuyOrder::ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT => $stop->getExchangeOrderId(),
            BuyOrder::OPPOSITE_SL_ID_CONTEXT => $stop->getId(),
        ];

        $side = $stop->getPositionSide();

        $distance = $length instanceof PredefinedStopLengthSelector ? $this->getOppositeOrderDistance($stop->getSymbol(), $refPrice, $length) : $length;

        $price = $side->isShort() ? $refPrice->sub($distance) : $refPrice->add($distance);
        $volume = $stop->getSymbol()->roundVolume($volume);
        $context = array_merge($commonContext, $additionalContext);

        return new Order($price, $volume, $context);
    }

    /**
     * @param PredefinedStopLengthSelector $lengthSelector // @todo | oppositeBuyOrder | use param from stop?
     */
    private function getOppositeOrderDistance(SymbolInterface $symbol, SymbolPrice $refPrice, PredefinedStopLengthSelector $lengthSelector): float
    {
        return PnlHelper::convertPnlPercentOnPriceToAbsDelta(
            PnlHelper::transformPriceChangeToPnlPercent(
                $this->tradingParametersProvider->regularOppositeBuyOrderLength($symbol, $lengthSelector, self::DEFAULT_ATR_TIMEFRAME, self::DEFAULT_ATR_PERIOD)
            ),
            $refPrice
        );
    }

    public function __construct(
        private readonly PositionServiceInterface $positionService,
        private readonly StopRepository $stopRepository,
        private readonly TradingParametersProviderInterface $tradingParametersProvider,
        private readonly CreateBuyOrderHandler $createBuyOrderHandler,
    ) {
    }
}
