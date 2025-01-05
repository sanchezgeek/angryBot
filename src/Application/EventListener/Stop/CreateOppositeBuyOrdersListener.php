<?php

declare(strict_types=1);

namespace App\Application\EventListener\Stop;

use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderEntryDto;
use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderHandler;
use App\Bot\Application\Settings\TradingSettings;
use App\Bot\Domain\Entity\BuyOrder;
use App\Domain\Stop\Event\StopPushedToExchange;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;
use App\Settings\Application\Service\AppSettingsProvider;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class CreateOppositeBuyOrdersListener
{
    public const OPPOSITE_SL_PRICE_MODIFIER = 1.2;

    private Percent $longOppositePnlDistance;
    private Percent $shortOppositePnlDistance;

    public function __construct(
        private readonly CreateBuyOrderHandler $createBuyOrderHandler,
        private readonly AppSettingsProvider $settings,
    ) {
        $this->longOppositePnlDistance = Percent::string($this->settings->get(TradingSettings::Opposite_BuyOrder_PnlDistance_ForLongPosition), false);
        $this->shortOppositePnlDistance = Percent::string($this->settings->get(TradingSettings::Opposite_BuyOrder_PnlDistance_ForShortPosition), false);
    }

    public function __invoke(StopPushedToExchange $event): void
    {
        $stop = $event->stop;
        $symbol = $stop->getSymbol();
        if (!$stop->isWithOppositeOrder()) {
            return;
        }

        $side = $stop->getPositionSide();
        $stopVolume = $stop->getVolume();
        $stopPrice = $symbol->makePrice($stop->getPrice()); // $price = $stop->getOriginalPrice() ?? $stop->getPrice();

        if (($distance = $stop->getOppositeBuyOrderDistance()) === null) {
            $pnlDistance = $side->isLong() ? $this->longOppositePnlDistance : $this->shortOppositePnlDistance;
            $distance = FloatHelper::modify(PnlHelper::convertPnlPercentOnPriceToAbsDelta($pnlDistance, $stopPrice), 0.1, 0.2);
        }

        $triggerPrice = $side->isShort() ? $stopPrice->sub($distance) : $stopPrice->add($distance);
        $triggerPrice = $triggerPrice->value();

        $context = [
            BuyOrder::IS_OPPOSITE_AFTER_SL_CONTEXT => true,
            BuyOrder::ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT => $stop->getExchangeOrderId(),
            BuyOrder::OPPOSITE_SL_ID_CONTEXT => $stop->getId(),
            BuyOrder::FORCE_BUY_CONTEXT => true,
        ];

         if ($stop->isAdditionalStopFromLiquidationHandler()) {
             $context[BuyOrder::WITHOUT_OPPOSITE_ORDER_CONTEXT] = true;
         } else {
             $context[BuyOrder::STOP_DISTANCE_CONTEXT] = FloatHelper::modify($distance * self::OPPOSITE_SL_PRICE_MODIFIER, 0.1);
         }

        $bigStopVolume = $symbol->roundVolume($symbol->minOrderQty() * 6);

        $orders = [
            ['volume' => $stopVolume >= $bigStopVolume ? $symbol->roundVolume($stopVolume / 3) : $stopVolume, 'price' => $symbol->makePrice($triggerPrice)->value()]
        ];

        if ($stopVolume >= $bigStopVolume) {
            $orders[] = [
                'volume' => $symbol->roundVolume($stopVolume / 4.5),
                'price' => $symbol->makePrice($side->isShort() ? $triggerPrice - $distance / 3.8 : $triggerPrice + $distance / 3.8)->value(),
            ];
            $orders[] = [
                'volume' => $symbol->roundVolume($stopVolume / 3.5),
                'price' => $symbol->makePrice($side->isShort() ? $triggerPrice - $distance / 2 : $triggerPrice + $distance / 2)->value(),
            ];
        }

        foreach ($orders as $order) {
            $this->createBuyOrderHandler->handle(
                new CreateBuyOrderEntryDto($symbol, $side, $order['volume'], $order['price'], $context)
            );
        }
    }
}
