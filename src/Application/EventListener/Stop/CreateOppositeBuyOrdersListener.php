<?php

declare(strict_types=1);

namespace App\Application\EventListener\Stop;

use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderEntryDto;
use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderHandler;
use App\Bot\Application\Settings\TradingSettings;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Collection\OrdersCollection;
use App\Domain\Order\Collection\OrdersLimitedWithMaxVolume;
use App\Domain\Order\Collection\OrdersWithMinExchangeVolume;
use App\Domain\Order\Order;
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

    private const MAIN_SYMBOLS = [
        Symbol::BTCUSDT,
        Symbol::ETHUSDT
    ];

    public const DISTANCES = [
        Symbol::ARCUSDT->value => 400,
    ];

    private Percent $longOppositePnlDistance;
    private Percent $shortOppositePnlDistance;

    private Percent $longOppositePnlDistanceForAltCoin;
    private Percent $shortOppositePnlDistanceForAltCoin;

    public function __construct(
        private readonly CreateBuyOrderHandler $createBuyOrderHandler,
        private readonly AppSettingsProvider $settings,
    ) {
        $this->longOppositePnlDistance = Percent::string($this->settings->get(TradingSettings::Opposite_BuyOrder_PnlDistance_ForLongPosition), false);
        $this->shortOppositePnlDistance = Percent::string($this->settings->get(TradingSettings::Opposite_BuyOrder_PnlDistance_ForShortPosition), false);

        $this->longOppositePnlDistanceForAltCoin = Percent::string($this->settings->get(TradingSettings::Opposite_BuyOrder_PnlDistance_ForLongPosition_AltCoin), false);
        $this->shortOppositePnlDistanceForAltCoin = Percent::string($this->settings->get(TradingSettings::Opposite_BuyOrder_PnlDistance_ForShortPosition_AltCoin), false);
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
            $pnlDistance = $this->getOppositeOrderPnlDistance($stop);
            $distance = FloatHelper::modify(PnlHelper::convertPnlPercentOnPriceToAbsDelta($pnlDistance, $stopPrice), 0.1, 0.2);
        }

        $triggerPrice = $side->isShort() ? $stopPrice->sub($distance) : $stopPrice->add($distance);

        $context = [
            BuyOrder::IS_OPPOSITE_AFTER_SL_CONTEXT => true,
            BuyOrder::ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT => $stop->getExchangeOrderId(),
            BuyOrder::OPPOSITE_SL_ID_CONTEXT => $stop->getId(),
        ];

        # force buy only if it's not auto stop-order CheckPositionIsUnderLiquidationHandler
        if (!$stop->isAdditionalStopFromLiquidationHandler()) {
            $context[BuyOrder::FORCE_BUY_CONTEXT] = true;
        }

        if ($stop->isAdditionalStopFromLiquidationHandler()) {
            $context[BuyOrder::WITHOUT_OPPOSITE_ORDER_CONTEXT] = true;
        } else {
            $context[BuyOrder::STOP_DISTANCE_CONTEXT] = FloatHelper::modify($distance * self::OPPOSITE_SL_PRICE_MODIFIER, 0.1);
        }

        $bigStopVolume = $symbol->roundVolume($symbol->minOrderQty() * 6);

        if ($stopVolume >= $bigStopVolume) {
            $orders = [
                new Order($triggerPrice, $symbol->roundVolume($stopVolume / 3)),
                new Order($side->isShort() ? $triggerPrice->sub($distance / 3.8) : $triggerPrice->add($distance / 3.8), $symbol->roundVolume($stopVolume / 4.5)),
                new Order($side->isShort() ? $triggerPrice->sub($distance / 2)   : $triggerPrice->add($distance / 2),   $symbol->roundVolume($stopVolume / 3.5)),
            ];
        } else {
            $orders = [
                new Order($triggerPrice, $symbol->roundVolume($stopVolume))
            ];
        }

        $orders = new OrdersLimitedWithMaxVolume(
            new OrdersWithMinExchangeVolume($symbol, new OrdersCollection(...$orders)),
            $stopVolume
        );

        foreach ($orders as $order) {
            $this->createBuyOrderHandler->handle(
                new CreateBuyOrderEntryDto($symbol, $side, $order->volume(), $order->price()->value(), $context)
            );
        }
    }

    public function getOppositeOrderPnlDistance(Stop $stop): Percent
    {
        $symbol = $stop->getSymbol();
        if (isset(self::DISTANCES[$symbol->value])) {
            return new Percent(self::DISTANCES[$symbol->value]);
        }

        if (!in_array($symbol, self::MAIN_SYMBOLS, true)) {
            return $stop->getPositionSide()->isLong() ? $this->longOppositePnlDistanceForAltCoin : $this->shortOppositePnlDistanceForAltCoin;
        }

        return $stop->getPositionSide()->isLong() ? $this->longOppositePnlDistance : $this->shortOppositePnlDistance;
    }
}
