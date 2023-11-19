<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Order\Service;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\CoinAmount;
use App\Domain\Order\ExchangeOrder;
use App\Domain\Order\Leverage;
use App\Domain\Order\Service\OrderCostHelper;
use App\Domain\Price\Price;
use App\Infrastructure\ByBit\Service\ByBitCommissionProvider;
use PHPUnit\Framework\TestCase;

final class OrderCostHelperTest extends TestCase
{
    private OrderCostHelper $helper;

    protected function setUp(): void
    {
        $this->helper = new OrderCostHelper(new ByBitCommissionProvider());
    }

    public function testLinearOrderMargin(): void
    {
        $symbol = Symbol::BTCUSDT;
        $qty = 0.001;
        $price = 34739;

        $leverage = new Leverage(100);

        $order = new ExchangeOrder($symbol, $qty, Price::float($price));

        $marginAmount = $this->helper->getOrderMargin($order, $leverage);

        self::assertEquals(new CoinAmount($symbol->associatedCoin(), 0.34739), $marginAmount);

        self::assertEquals($symbol->associatedCoin(), $marginAmount->coin());
        self::assertEquals(0.347, $marginAmount->value());
    }

    public function testLinearOrderBuyCost(): void
    {
        $symbol = Symbol::BTCUSDT;
        $qty = 0.001;
        $price = 34739;

        $leverage = new Leverage(100);

        $order = new ExchangeOrder($symbol, $qty, Price::float($price));

        $buyCost = $this->helper->getOrderBuyCost($order, $leverage);

        self::assertEquals(new CoinAmount($symbol->associatedCoin(), 0.38560289999999997), $buyCost);

        self::assertEquals($symbol->associatedCoin(), $buyCost->coin());
        self::assertEquals(0.386, $buyCost->value());
    }
}
