<?php

declare(strict_types=1);

namespace App\Application\EventListener\Stop;

use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderEntryDto;
use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderHandler;
use App\Bot\Domain\Entity\BuyOrder;
use App\Domain\Price\Helper\PriceHelper;
use App\Domain\Stop\Event\StopPushedToExchange;
use App\Helper\FloatHelper;
use App\Helper\VolumeHelper;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class CreateOppositeBuyOrdersListener
{
    /*
     * @todo | MAIN_POSITION_..._OPPOSITE_PRICE_DISTANCE, SUPPORT_..._OPPOSITE_PRICE_DISTANCE ?
     */
    public const SHORT_OPPOSITE_PRICE_DISTANCE = 300;
    public const LONG_OPPOSITE_PRICE_DISTANCE = 400;

    public function __construct(private readonly CreateBuyOrderHandler $createBuyOrderHandler) {}

    public function __invoke(StopPushedToExchange $event): void
    {
        $stop = $event->stop;
        if (!$stop->isWithOppositeOrder()) {
            return;
        }

        $side = $stop->getPositionSide();
        $stopVolume = $stop->getVolume();
        $stopPrice = $stop->getPrice(); // $price = $stop->getOriginalPrice() ?? $stop->getPrice();

        $distance = FloatHelper::modify($side->isShort() ? self::SHORT_OPPOSITE_PRICE_DISTANCE : self::LONG_OPPOSITE_PRICE_DISTANCE, 0.1, 0.2);
        $triggerPrice = $side->isShort() ? $stopPrice - $distance : $stopPrice + $distance;

        $context = [
            BuyOrder::IS_OPPOSITE_AFTER_SL_CONTEXT => true,
            BuyOrder::ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT => $stop->getExchangeOrderId(),
            BuyOrder::STOP_DISTANCE_CONTEXT => FloatHelper::modify($distance, 0.1),
            BuyOrder::FORCE_BUY_CONTEXT => true,
        ];

        $orders = [
            ['volume' => $stopVolume >= 0.006 ? VolumeHelper::round($stopVolume / 3) : $stopVolume, 'price' => $triggerPrice]
        ];

        if ($stopVolume >= 0.006) {
            $orders[] = [
                'volume' => VolumeHelper::round($stopVolume / 4.5),
                'price' => PriceHelper::round($side->isShort() ? $triggerPrice - $distance / 3.8 : $triggerPrice + $distance / 3.8),
            ];
            $orders[] = [
                'volume' => VolumeHelper::round($stopVolume / 3.5),
                'price' => PriceHelper::round($side->isShort() ? $triggerPrice - $distance / 2 : $triggerPrice + $distance / 2),
            ];
        }

        foreach ($orders as $order) {
            $this->createBuyOrderHandler->handle(
                new CreateBuyOrderEntryDto($side, $order['volume'], $order['price'], $context)
            );
        }
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
