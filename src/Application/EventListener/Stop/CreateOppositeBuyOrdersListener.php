<?php

declare(strict_types=1);

namespace App\Application\EventListener\Stop;

use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderEntryDto;
use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderHandler;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Helper\PriceHelper;
use App\Domain\Stop\Event\StopPushedToExchange;
use App\Helper\FloatHelper;
use App\Helper\VolumeHelper;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

use function random_int;

#[AsEventListener]
final class CreateOppositeBuyOrdersListener
{
    /*
     * @todo MAIN_POSITION_..._OPPOSITE_PRICE_DISTANCE, SUPPORT_..._OPPOSITE_PRICE_DISTANCE
     */
    public const SHORT_BUY_ORDER_OPPOSITE_PRICE_DISTANCE = 500;
    public const LONG_BUY_ORDER_OPPOSITE_PRICE_DISTANCE = 400;

    public function __construct(
        private readonly CreateBuyOrderHandler $createBuyOrderHandler,
    ) {
    }

    public function __invoke(StopPushedToExchange $event): void
    {
        $stop = $event->stop;

        if ($stop->isWithOppositeOrder()) {
            $this->createOpposite($stop);
        }
    }

    /**
     * @return array<array{volume: float, price: float}>
     */
    private function createOpposite(Stop $stop): array
    {
        $side = $stop->getPositionSide();
        $price = $stop->getPrice(); // $price = $stop->getOriginalPrice() ?? $stop->getPrice();
        // @todo | how to check in tests?

        $distance = FloatHelper::modify($this->getBuyOrderOppositePriceDistance($side), 0.1);

        $triggerPrice = $side === Side::Sell ? $price - $distance : $price + $distance;
        $volume = $stop->getVolume() >= 0.006 ? VolumeHelper::round($stop->getVolume() / 3) : $stop->getVolume();

        $context = [
            BuyOrder::IS_OPPOSITE_AFTER_SL_CONTEXT => true,
            BuyOrder::ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT => $stop->getExchangeOrderId(),
        ];

        $orders = [
            ['volume' => $volume, 'price' => $triggerPrice]
        ];

        if ($stop->getVolume() >= 0.006) {
            $orders[] = [
                'volume' => VolumeHelper::round($stop->getVolume() / 4.5),
                'price' => PriceHelper::round(
                    $side === Side::Sell ? $triggerPrice - $distance / 3.8 : $triggerPrice + $distance / 3.8
                ),
            ];
            $orders[] = [
                'volume' => VolumeHelper::round($stop->getVolume() / 3.5),
                'price' => PriceHelper::round(
                    $side === Side::Sell ? $triggerPrice - $distance / 2 : $triggerPrice + $distance / 2
                ),
            ];
        }

        foreach ($orders as $order) {
            $this->createBuyOrderHandler->handle(
                new CreateBuyOrderEntryDto($side, $order['volume'], $order['price'], $context)
            );
        }

        return $orders;
    }

    private function getBuyOrderOppositePriceDistance(Side $side): float
    {
        return $side->isLong() ? self::LONG_BUY_ORDER_OPPOSITE_PRICE_DISTANCE : self::SHORT_BUY_ORDER_OPPOSITE_PRICE_DISTANCE;
    }
}

//        private const SL_SUPPORT_FROM_MAIN_HEDGE_POSITION_TRIGGER_DELTA = 5;

//        $isHedge = ($oppositePosition = $this->positionService->getOppositePosition($position)) !== null;
//        $isHedge = false;
//        if ($isHedge) {
//            $hedge = Hedge::create($position, $oppositePosition);
//            // If this is support position, we need to make sure that we can afford opposite buy after stop (which was added, for example, by mistake)
//            if (
//                $hedge->isSupportPosition($position)
//                && $hedge->needIncreaseSupport()
//            ) {
//                $vol = VolumeHelper::round($volume / 3);
//                if ($vol > 0.005) $vol = 0.005;
//
//                $price = $oppositePosition->side === Side::Sell ? ($triggerPrice - 3) : ($triggerPrice + 3);
//                $this->stopService->create($oppositePosition->side, $price, $vol, self::SL_SUPPORT_FROM_MAIN_HEDGE_POSITION_TRIGGER_DELTA, ['asSupportFromMainHedgePosition' => true, 'createdWhen' => 'tryGetHelpFromHandler']);
//            } elseif (
//                $hedge->isMainPosition($position)
//                && $ticker->isIndexAlreadyOverStop($position->side, $position->entryPrice) // MainPosition now in loss
//                && !$hedge->needKeepSupportSize()
//            ) {
//                // @todo Need async job instead (to check $hedge->needKeepSupportSize() in future, if now still need keep support size)
//                // Or it can be some problems at runtime...Need async job
//                $this->hedgeService->createStopIncrementalGridBySupport($hedge, $stop);
//            }
//            // @todo Придумать нормульную логику (доделать проверку баланса и необходимость в фиксации main-позиции?)
//            // Пока что добавил отлов CannotAffordOrderCost в PushBuyOrdersHandler при попытке купить
//        }
