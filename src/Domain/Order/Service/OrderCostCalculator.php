<?php

declare(strict_types=1);

namespace App\Domain\Order\Service;

use App\Domain\Coin\CoinAmount;
use App\Domain\Exchange\Service\ExchangeCommissionProvider;
use App\Domain\Order\ExchangeOrder;
use App\Domain\Position\ValueObject\Leverage;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;

/**
 * @link https://www.bybit.com/en/help-center/article/Order-Cost-USDT-ContractUSDT_Perpetual_Contract
 *
 * @todo | MB interface?
 */
final class OrderCostCalculator
{
    private ExchangeCommissionProvider $exchangeCommissionProvider;

    public function __construct(ExchangeCommissionProvider $exchangeCommissionProvider)
    {
        $this->exchangeCommissionProvider = $exchangeCommissionProvider;
    }

    public function orderMargin(ExchangeOrder $order, Leverage $leverage): CoinAmount
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

    public function totalBuyCost(ExchangeOrder $order, Leverage $leverage, Side $side): CoinAmount
    {
        return
            $this->orderMargin($order, $leverage)
                ->add($this->openFee($order))
                ->add($this->closeFee($order, $leverage, $side))
            ;
    }

    public function openFee(ExchangeOrder $order): CoinAmount
    {
        $value = $this->exchangeCommissionProvider->getTakerFee()->of($order->getVolume() * $order->getPrice()->value());

        return new CoinAmount($order->getSymbol()->associatedCoin(), $value);
    }

    public function closeFee(ExchangeOrder $order, Leverage $leverage, Side $side): CoinAmount
    {
        $bankruptcyPrice = $order->getPrice()->value() * ($side->isLong() ? $leverage->value() - 1 : $leverage->value() + 1) / $leverage->value();
        $value = $this->exchangeCommissionProvider->getTakerFee()->of($order->getVolume() * $bankruptcyPrice);

        return new CoinAmount($order->getSymbol()->associatedCoin(), $value);
    }
}
