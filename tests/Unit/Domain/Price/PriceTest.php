<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Price;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Enum\PriceMovementDirection;
use App\Domain\Price\Exception\PriceCannotBeLessThanZero;
use App\Domain\Price\SymbolPrice;
use App\Domain\Price\PriceMovement;
use App\Domain\Price\PriceRange;
use App\Tests\Factory\PositionFactory;
use Exception;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Domain\Price\SymbolPrice
 */
final class PriceTest extends TestCase
{
    /**
     * @dataProvider canCreateCases
     */
    public function testCanCreateAndGetValue(float $value, float $expectedValue): void
    {
        $price = SymbolPrice::create($value, SymbolEnum::BTCUSDT);

        self::assertEquals($expectedValue, $price->value());
    }

    private function canCreateCases(): iterable
    {
        return [
            [0.01, 0.01],
            [1, 1],
            [1.01, 1.01],
            [1.001, 1],
            [1.041, 1.04],
            [1.096, 1.1],
            [999.99, 999.99],
            [999.499, 999.5],
            [999.999, 1000],
        ];
    }

    /**
     * @dataProvider failCreateCases
     */
    public function testFailCreate(SymbolInterface $symbol, float $value, Exception $expectedException): void
    {
        $this->expectExceptionObject($expectedException);

        SymbolPrice::create($value, $symbol);
    }

    private function failCreateCases(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;

        return [
            [$symbol, -1, new PriceCannotBeLessThanZero(-1, $symbol)],
            [$symbol, -0.009, new PriceCannotBeLessThanZero(-0.009, $symbol)],
        ];
    }

    /**
     * @dataProvider addTestDataProvider
     */
    public function testCanAdd(SymbolPrice $initialPrice, float $addValue, SymbolPrice $expectedResult): void
    {
        # with float
        $result = $initialPrice->add($addValue);

        self::assertNotSame($initialPrice, $result);
        self::assertTrue($result->eq($expectedResult));

        # with object
        $addValue = $initialPrice->symbol->makePrice($addValue);
        $result = $initialPrice->add($addValue);

        self::assertNotSame($initialPrice, $result);
        self::assertTrue($result->eq($expectedResult));
    }

    private function addTestDataProvider(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;

        yield [$symbol->makePrice(123.45), 1, $symbol->makePrice(124.45)];
        yield [$symbol->makePrice(123.45), 1.002, $symbol->makePrice(124.452)];
        yield [$symbol->makePrice(123.45), 2.1, $symbol->makePrice(125.55)];
        yield [$symbol->makePrice(123.45), 2.101, $symbol->makePrice(125.551)];
        yield [$symbol->makePrice(123), 2.1, $symbol->makePrice(125.1)];
        yield [$symbol->makePrice(123), 2.101, $symbol->makePrice(125.101)];
    }

    /**
     * @dataProvider subTestDataProvider
     */
    public function testCanSub(SymbolPrice $initialPrice, SymbolPrice|float $subValue, SymbolPrice $expectedResult): void
    {
        # with float
        $result = $initialPrice->sub($subValue);

        self::assertNotSame($initialPrice, $result);
        self::assertTrue($result->eq($expectedResult));

        # with object
        $subValue = $initialPrice->symbol->makePrice($subValue);
        $result = $initialPrice->sub($subValue);

        self::assertNotSame($initialPrice, $result);
        self::assertTrue($result->eq($expectedResult));
    }

    private function subTestDataProvider(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;

        yield [$symbol->makePrice(123.45), 1, $symbol->makePrice(122.45)];
        yield [$symbol->makePrice(123.452), 1.001, $symbol->makePrice(122.451)];
        yield [$symbol->makePrice(123.46), 2.1, $symbol->makePrice(121.36)];
        yield [$symbol->makePrice(123.46), 2.101, $symbol->makePrice(121.359)];
        yield [$symbol->makePrice(123), 2.1, $symbol->makePrice(120.9)];
    }

    /**
     * @dataProvider greaterThanTestDataProvider
     */
    public function testGreaterThan(SymbolPrice $a, SymbolPrice $b, bool $expectedResult): void
    {
        self::assertSame($expectedResult, $a->greaterThan($b));
    }

    private function greaterThanTestDataProvider(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;

        yield [$symbol->makePrice(123), $symbol->makePrice(456), false];
        yield [$symbol->makePrice(455.999), $symbol->makePrice(456), false];
        yield [$symbol->makePrice(456), $symbol->makePrice(456), false];
        yield [$symbol->makePrice(456.001), $symbol->makePrice(456), true];
    }

    /**
     * @dataProvider greaterOrEqualsTestDataProvider
     */
    public function testGreaterOrEquals(SymbolPrice $a, SymbolPrice $b, bool $expectedResult): void
    {
        self::assertSame($expectedResult, $a->greaterOrEquals($b));
    }

    private function greaterOrEqualsTestDataProvider(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;

        yield [$symbol->makePrice(123), $symbol->makePrice(456), false];
        yield [$symbol->makePrice(455.999), $symbol->makePrice(456), false];
        yield [$symbol->makePrice(456), $symbol->makePrice(456), true];
        yield [$symbol->makePrice(456.01), $symbol->makePrice(456), true];
    }

    /**
     * @dataProvider lessThanTestDataProvider
     */
    public function testLessThan(SymbolPrice $a, SymbolPrice $b, bool $expectedResult): void
    {
        self::assertSame($expectedResult, $a->lessThan($b));
    }

    private function lessThanTestDataProvider(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;

        yield [$symbol->makePrice(456), $symbol->makePrice(123), false];
        yield [$symbol->makePrice(456), $symbol->makePrice(455.999), false];
        yield [$symbol->makePrice(456), $symbol->makePrice(456), false];
        yield [$symbol->makePrice(456), $symbol->makePrice(456.001), true];
    }

    /**
     * @dataProvider lessOrEqualsTestDataProvider
     */
    public function testLessOrEquals(SymbolPrice $a, SymbolPrice $b, bool $expectedResult): void
    {
        self::assertSame($expectedResult, $a->lessOrEquals($b));
    }

    private function lessOrEqualsTestDataProvider(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;

        yield [$symbol->makePrice(456), $symbol->makePrice(123), false];
        yield [$symbol->makePrice(456), $symbol->makePrice(455.999), false];
        yield [$symbol->makePrice(456), $symbol->makePrice(456), true];
        yield [$symbol->makePrice(456), $symbol->makePrice(456.001), true];
    }

    /**
     * @dataProvider priceIsOverTakeProfitTestCases
     */
    public function testIsPriceOverTakeProfit(SymbolPrice $price, Side $positionSide, float $takeProfitPrice, bool $expectedResult): void
    {
        $result = $price->isPriceOverTakeProfit($positionSide, $takeProfitPrice);

        self::assertEquals($expectedResult, $result);
    }

    private function priceIsOverTakeProfitTestCases(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;

        yield 'over SHORT TP (+)' => [
            'price' => $symbol->makePrice(200500),
            'stop.positionSide' => Side::Sell,
            'takeProfit.price' => 200500,
            'expectedResult' => true,
        ];

        yield 'over SHORT TP (-)' => [
            'price' => $symbol->makePrice(200500),
            'stop.positionSide' => Side::Sell,
            'takeProfit.price' => 200499,
            'expectedResult' => false,
        ];

        yield 'over LONG TP (+)' => [
            'price' => $symbol->makePrice(200500),
            'stop.positionSide' => Side::Buy,
            'takeProfit.price' => 200500,
            'expectedResult' => true,
        ];

        yield 'over LONG TP (-)' => [
            'price' => $symbol->makePrice(200500),
            'stop.positionSide' => Side::Buy,
            'takeProfit.price' => 200501,
            'expectedResult' => false,
        ];
    }

    /**
     * @dataProvider priceInLossOfOther
     */
    public function testIsPriceInLossOfOther(SymbolPrice $price, Side $positionSide, float $takeProfitPrice, bool $expectedResult): void
    {
        $result = $price->isPriceInLossOfOther($positionSide, $takeProfitPrice);

        self::assertEquals($expectedResult, $result);
    }

    private function priceInLossOfOther(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;

        yield 'over SHORT SL (+)' => [
            'price' => $symbol->makePrice(200501.01),
            'stop.positionSide' => Side::Sell,
            'stop.price' => 200500,
            'expectedResult' => true,
        ];

        yield 'over SHORT SL (-)' => [
            'price' => $symbol->makePrice(200501),
            'stop.positionSide' => Side::Sell,
            'stop.price' => 200501,
            'expectedResult' => false,
        ];

        yield 'over LONG SL (+)' => [
            'price' => $symbol->makePrice(200500),
            'stop.positionSide' => Side::Buy,
            'stop.price' => 200500.01,
            'expectedResult' => true,
        ];

        yield 'over LONG SL (-)' => [
            'price' => $symbol->makePrice(200500),
            'stop.positionSide' => Side::Buy,
            'stop.price' => 200500,
            'expectedResult' => false,
        ];
    }

    public function testDifferenceWith(): void
    {
        $symbol = SymbolEnum::BTCUSDT;

        $currentPrice = $symbol->makePrice(100500);
        $fromPrice = $symbol->makePrice(200500);

        self::assertEquals(PriceMovement::fromToTarget($fromPrice, $currentPrice), $currentPrice->differenceWith($fromPrice));
    }

    /**
     * @dataProvider isPriceInRangeTestCases
     */
    public function testIsPriceInRange(SymbolPrice $price, PriceRange $priceRange, $expectedResult): void
    {
        $result = $price->isPriceInRange($priceRange);

        self::assertEquals($expectedResult, $result);
    }

    public function isPriceInRangeTestCases(): array
    {
        $symbol = SymbolEnum::BTCUSDT;

        return [
            [$symbol->makePrice(100500), PriceRange::create(100500, 200500, $symbol), true],
            [$symbol->makePrice(200000), PriceRange::create(100500, 200500, $symbol), true],
            [$symbol->makePrice(200500), PriceRange::create(100500, 200500, $symbol), true],
            [$symbol->makePrice(100499), PriceRange::create(100500, 200500, $symbol), false],
            [$symbol->makePrice(200501), PriceRange::create(100500, 200500, $symbol), false],
        ];
    }

    public function testGetPnlInPercents(): void
    {
        $symbol = SymbolEnum::BTCUSDT;

        ## SHORT
        $position = PositionFactory::short($symbol, 30000, 1, 100);

        self::assertEquals(-120, $symbol->makePrice(30360)->getPnlPercentFor($position));
        self::assertEquals(-20, $symbol->makePrice(30060)->getPnlPercentFor($position));
        self::assertEquals(0, $symbol->makePrice(30000)->getPnlPercentFor($position));
        self::assertEquals(20, $symbol->makePrice(29940)->getPnlPercentFor($position));
        self::assertEquals(100, $symbol->makePrice(29700)->getPnlPercentFor($position));

        ## LONG
        $position = PositionFactory::long($symbol, 30000, 1, 100);

        self::assertEquals(120, $symbol->makePrice(30360)->getPnlPercentFor($position));
        self::assertEquals(20, $symbol->makePrice(30060)->getPnlPercentFor($position));
        self::assertEquals(0, $symbol->makePrice(30000)->getPnlPercentFor($position));
        self::assertEquals(-20, $symbol->makePrice(29940)->getPnlPercentFor($position));
        self::assertEquals(-100, $symbol->makePrice(29700)->getPnlPercentFor($position));
    }

    public function testGetTargetPriceByPnlPercent(): void
    {
        $symbol = SymbolEnum::BTCUSDT;

        ## SHORT
        $position = PositionFactory::short($symbol, 100500, 1, 100);
        $price = $symbol->makePrice(30000);

        self::assertEquals($symbol->makePrice(30360), $price->getTargetPriceByPnlPercent(-120, $position));
        self::assertEquals($symbol->makePrice(30300), $price->getTargetPriceByPnlPercent(-100, $position));
        self::assertEquals($symbol->makePrice(30060), $price->getTargetPriceByPnlPercent(-20, $position));
        self::assertEquals($symbol->makePrice(30000), $price->getTargetPriceByPnlPercent(0, $position));
        self::assertEquals($symbol->makePrice(29940), $price->getTargetPriceByPnlPercent(20, $position));
        self::assertEquals($symbol->makePrice(29700), $price->getTargetPriceByPnlPercent(100, $position));
        self::assertEquals($symbol->makePrice(29640), $price->getTargetPriceByPnlPercent(120, $position));

        ## LONG
        $position = PositionFactory::long($symbol, 100500, 1, 100);

        self::assertEquals($symbol->makePrice(30360), $price->getTargetPriceByPnlPercent(120, $position));
        self::assertEquals($symbol->makePrice(30300), $price->getTargetPriceByPnlPercent(100, $position));
        self::assertEquals($symbol->makePrice(30060), $price->getTargetPriceByPnlPercent(20, $position));
        self::assertEquals($symbol->makePrice(30000), $price->getTargetPriceByPnlPercent(0, $position));
        self::assertEquals($symbol->makePrice(29940), $price->getTargetPriceByPnlPercent(-20, $position));
        self::assertEquals($symbol->makePrice(29700), $price->getTargetPriceByPnlPercent(-100, $position));
        self::assertEquals($symbol->makePrice(29640), $price->getTargetPriceByPnlPercent(-120, $position));
    }

    public function testModifyByDirection(): void
    {
        $symbol = SymbolEnum::BTCUSDT;

        self::assertEquals($symbol->makePrice(50000.2), $symbol->makePrice(50000)->modifyByDirection(Side::Sell, PriceMovementDirection::TO_LOSS, 0.2));
        self::assertEquals($symbol->makePrice(50000.2), $symbol->makePrice(50000)->modifyByDirection(Side::Buy, PriceMovementDirection::TO_PROFIT, 0.2));

        self::assertEquals($symbol->makePrice(49999.8), $symbol->makePrice(50000)->modifyByDirection(Side::Buy, PriceMovementDirection::TO_LOSS, 0.2));
        self::assertEquals($symbol->makePrice(49999.8), $symbol->makePrice(50000)->modifyByDirection(Side::Sell, PriceMovementDirection::TO_PROFIT, 0.2));
    }
}
