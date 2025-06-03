<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Order\Service;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Coin\CoinAmount;
use App\Domain\Order\ExchangeOrder;
use App\Domain\Order\Service\OrderCostCalculator;
use App\Domain\Position\ValueObject\Leverage;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;
use App\Infrastructure\ByBit\Service\ByBitCommissionProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Domain\Order\Service\OrderCostCalculator
 */
final class ByBitOrderCostCalculatorTest extends TestCase
{
    private OrderCostCalculator $orderCostCalculator;

    protected function setUp(): void
    {
        $this->orderCostCalculator = new OrderCostCalculator(new ByBitCommissionProvider());
    }

    public function testLinearOrderMargin(): void
    {
        $symbol = SymbolEnum::BTCUSDT;
        $qty = 0.001;
        $price = 34739;

        $leverage = new Leverage(100);

        $order = new ExchangeOrder($symbol, $qty, $symbol->makePrice($price));

        $marginAmount = $this->orderCostCalculator->orderMargin($order, $leverage);

        self::assertEquals($symbol->associatedCoinAmount(0.34739), $marginAmount);
    }

    /**
     * @dataProvider linearOrderBuyCostTestData
     */
    public function testLinearOrderBuyCost(
        Side $positionSide,
        float $price,
        float $qty,
        SymbolInterface $symbol,
        float $expectedCost,
    ): void {
        $leverage = new Leverage(100);
        $order = new ExchangeOrder($symbol, $qty, $price);

        $buyCost = $this->orderCostCalculator->totalBuyCost($order, $leverage, $positionSide);

        self::assertEquals($symbol->associatedCoinAmount($expectedCost), $buyCost);
    }

    public function linearOrderBuyCostTestData(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;
        yield 'LONG' => [
            '$side' => Side::Buy,
            '$price' => 34739,
            '$qty' => 0.001,
            '$symbol' => $symbol,
            'expectedCost' => 0.38541183549999997
        ];

        yield 'SHORT' => [
            '$side' => Side::Sell,
            '$price' => 34739,
            '$qty' => 0.001,
            '$symbol' => $symbol,
            'expectedCost' => 0.3857939645
        ];
    }

    public function testLinearOpenFee(): void
    {
        $symbol = SymbolEnum::BTCUSDT;
        $price = $symbol->makePrice(34739);
        $qty = 0.001;
        $expectedOpenFee = 0.01910645;

        $order = new ExchangeOrder($symbol, $qty, $price);

        $buyCost = $this->orderCostCalculator->openFee($order);

        self::assertEquals($symbol->associatedCoinAmount($expectedOpenFee), $buyCost);
    }

    /**
     * @dataProvider linearOrderCloseFeeTestData
     */
    public function testLinearCloseFee(
        Side $positionSide,
        float $price,
        float $qty,
        SymbolInterface $symbol,
        float $expectedCloseFee,
    ): void {
        $leverage = new Leverage(100);
        $order = new ExchangeOrder($symbol, $qty, $price);

        $buyCost = $this->orderCostCalculator->closeFee($order, $leverage, $positionSide);

        self::assertEquals($symbol->associatedCoinAmount($expectedCloseFee), $buyCost);
    }

    public function linearOrderCloseFeeTestData(): iterable
    {
        yield 'LONG' => [
            '$side' => Side::Buy,
            '$price' => 34739,
            '$qty' => 0.001,
            '$symbol' => SymbolEnum::BTCUSDT,
            'expectedCloseFee' => 0.0189153855
        ];

        yield 'SHORT' => [
            '$side' => Side::Sell,
            '$price' => 34739,
            '$qty' => 0.001,
            '$symbol' => SymbolEnum::BTCUSDT,
            'expectedCloseFee' => 0.0192975145
        ];
    }
}
