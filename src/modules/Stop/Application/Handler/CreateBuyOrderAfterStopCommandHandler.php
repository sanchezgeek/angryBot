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
use App\Domain\Order\ExchangeOrder;
use App\Domain\Order\Order;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Trading\Enum\PredefinedStopLengthSelector;
use App\Domain\Trading\Enum\TimeFrame;
use App\Domain\Value\Percent\Percent;
use App\Stop\Application\Contract\Command\CreateBuyOrderAfterStop;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @see \App\Tests\Functional\Modules\Buy\Application\Handler\CreateBuyOrdersAfterStopCommandHandlerTest
 */
#[AsMessageHandler]
final class CreateBuyOrderAfterStopCommandHandler
{
    public const float OPPOSITE_SL_PRICE_MODIFIER = 1.2;
    public const int BIG_STOP_VOLUME_MULTIPLIER = 10;

    public const int DEFAULT_ATR_PERIOD = PredefinedStopLengthProcessor::DEFAULT_PERIOD_FOR_ATR;
    public const TimeFrame DEFAULT_ATR_TIMEFRAME = PredefinedStopLengthProcessor::DEFAULT_TIMEFRAME_FOR_ATR;

    public function __invoke(CreateBuyOrderAfterStop $command): array
    {
        $stop = $this->stopRepository->find($command->stopId);
        if (!$stop->isWithOppositeOrder()) {
            return [];
        }

        $symbol = $stop->getSymbol();
        $side = $stop->getPositionSide();
        $stopVolume = $stop->getVolume();
        $stopPrice = $symbol->makePrice($stop->getPrice()); // $price = $stop->getOriginalPrice() ?? $stop->getPrice();

        $distanceOverride = $stop->getOppositeOrderDistance();
        if ($distanceOverride !== null) {
            $baseDistance = $distanceOverride;
        } else {
            $baseDistance = $this->getOppositeOrderDistance($stop, PredefinedStopLengthSelector::Standard);
        }

        $baseBuyOrderPrice = $side->isShort() ? $stopPrice->sub($baseDistance) : $stopPrice->add($baseDistance);

        $context = [
            BuyOrder::IS_OPPOSITE_AFTER_SL_CONTEXT => true,
            BuyOrder::ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT => $stop->getExchangeOrderId(),
            BuyOrder::OPPOSITE_SL_ID_CONTEXT => $stop->getId(),
        ];
        # force buy only if it's not auto stop-order CheckPositionIsUnderLiquidationHandler // @todo | oppositeBuyOrder | only if source BuyOrder in chain was with force // if (!$stop->isAdditionalStopFromLiquidationHandler()) {$context[BuyOrder::FORCE_BUY_CONTEXT] = true;}

//        if ($stop->isAdditionalStopFromLiquidationHandler()) {
            // @todo | oppositeBuyOrder | some setting or conditions?
//            $context[BuyOrder::WITHOUT_OPPOSITE_ORDER_CONTEXT] = true;
            // @todo | oppositeBuyOrder | stop | либо за цену первоначального стопа?
//        } else {
        $context[BuyOrder::OPPOSITE_ORDERS_DISTANCE_CONTEXT] = $baseDistance * self::OPPOSITE_SL_PRICE_MODIFIER;
//        }

        $minOrderQty = ExchangeOrder::roundedToMin($symbol, $symbol->minOrderQty(), $stopPrice)->getVolume();
        $bigStopVolume = $symbol->roundVolume($minOrderQty * self::BIG_STOP_VOLUME_MULTIPLIER);

        if ($stopVolume >= $bigStopVolume) {
            if ($distanceOverride) {
                $volumeGrid = [
                    $symbol->roundVolume($stopVolume / 3),
                    $symbol->roundVolume($stopVolume / 4.5),
                    $symbol->roundVolume($stopVolume / 3.5),
                ];
                $priceGrid = [
                    $baseBuyOrderPrice,
                    $side->isShort() ? $baseBuyOrderPrice->sub($baseDistance / 3.8) : $baseBuyOrderPrice->add($baseDistance / 3.8),
                    $side->isShort() ? $baseBuyOrderPrice->sub($baseDistance / 2)   : $baseBuyOrderPrice->add($baseDistance / 2),
                ];
            } else {
                $volumeGrid = [
                    $symbol->roundVolume($stopVolume / 5),
                    $symbol->roundVolume($stopVolume / 5),
                    $symbol->roundVolume($stopVolume / 3),
                    $symbol->roundVolume($stopVolume / 3),
                ];

                $veryShortDistance = $this->getOppositeOrderDistance($stop, PredefinedStopLengthSelector::VeryShort);
                $moderateShortDistance = $this->getOppositeOrderDistance($stop, PredefinedStopLengthSelector::ModerateShort);
                $standardDistance = $this->getOppositeOrderDistance($stop, PredefinedStopLengthSelector::Standard);
                $longDistance = $this->getOppositeOrderDistance($stop, PredefinedStopLengthSelector::Long);

                $priceGrid = [
                    $side->isShort() ? $stopPrice->sub($veryShortDistance) : $stopPrice->add($veryShortDistance),
                    $side->isShort() ? $stopPrice->sub($moderateShortDistance) : $stopPrice->add($moderateShortDistance),
                    $side->isShort() ? $stopPrice->sub($standardDistance) : $stopPrice->add($standardDistance),
                    $side->isShort() ? $stopPrice->sub($longDistance) : $stopPrice->add($longDistance),
                ];
            }

            $orders = [];
            foreach ($priceGrid as $key => $price) {
                $orders[] = new Order($price, $volumeGrid[$key]);
            }
        } else {
            $orders = [
                new Order($baseBuyOrderPrice, $symbol->roundVolume($stopVolume))
            ];
        }

        $orders = new OrdersLimitedWithMaxVolume(
            new OrdersWithMinExchangeVolume($symbol, new OrdersCollection(...$orders)),
            $stopVolume
        );

        $buyOrders = [];
        foreach ($orders as $order) {
            $dto = new CreateBuyOrderEntryDto($symbol, $side, $order->volume(), $order->price()->value(), $context);
            $buyOrders[] = $this->createBuyOrderHandler->handle($dto)->buyOrder;
        }

        return $buyOrders;
    }

    public function getOppositeOrderDistance(
        Stop $stop,
        // @todo | oppositeBuyOrder | use param from stop?
        PredefinedStopLengthSelector $lengthSelector
    ): float {
        $stopPrice = $stop->getPrice();

        return PnlHelper::convertPnlPercentOnPriceToAbsDelta(
            PnlHelper::transformPriceChangeToPnlPercent(
                $this->tradingParametersProvider->regularOppositeBuyOrderLength($stop->getSymbol(), $lengthSelector, self::DEFAULT_ATR_TIMEFRAME, self::DEFAULT_ATR_PERIOD)
            ),
            $stopPrice
        );
    }

    public function __construct(
        private readonly StopRepository $stopRepository,
        private readonly TradingParametersProviderInterface $tradingParametersProvider,
        private readonly CreateBuyOrderHandler $createBuyOrderHandler,
    ) {
    }
}
