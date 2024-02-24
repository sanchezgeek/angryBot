<?php

declare(strict_types=1);

namespace App\Domain\Order\Service;

use App\Domain\Coin\CoinAmount;
use App\Domain\Exchange\Service\ExchangeCommissionProvider;
use App\Domain\Order\ExchangeOrder;
use App\Domain\Position\ValueObject\Leverage;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;

final class OrderCostHelper
{
    private ExchangeCommissionProvider $exchangeCommissionProvider;

    public function __construct(ExchangeCommissionProvider $exchangeCommissionProvider)
    {
        $this->exchangeCommissionProvider = $exchangeCommissionProvider;
    }

    public function getOrderMargin(ExchangeOrder $order, Leverage $leverage): CoinAmount
    {
        $symbol = $order->getSymbol();

        $qty = $order->getVolume();
        $price = $order->getPrice();

        $category = $symbol->associatedCategory();

        if ($category === AssetCategory::linear) {
            $contractCost = $price->value() / $leverage->value();
            $cost = $contractCost * $qty;
        } else {
            $contractCost = 1 / $price->value();
            $cost = $contractCost * $qty;
        }

        return new CoinAmount($symbol->associatedCoin(), $cost);
    }

    public function getOrderBuyCost(ExchangeOrder $order, Leverage $leverage): CoinAmount
    {
        $cost = $this->getOrderMargin($order, $leverage);
        $commission = $this->exchangeCommissionProvider->getExecOrderCommission();

        return $cost->addPercent($commission);
    }
}
