<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Price;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Enum\PriceMovementDirection;
use App\Domain\Price\Exception\PriceCannotBeLessThanZero;
use App\Domain\Price\Price;
use App\Domain\Price\PriceMovement;
use App\Domain\Price\PriceRange;
use App\Tests\Factory\PositionFactory;
use DomainException;
use Exception;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Domain\Price\Price
 */
final class PriceTest extends TestCase
{
    /**
     * @dataProvider canCreateCases
     */
    public function testCanCreateAndGetValue(float $value, float $expectedValue): void
    {
        $price = Price::float($value);

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
    public function testFailCreate(float $value, Exception $expectedException): void
    {
        $this->expectExceptionObject($expectedException);

        Price::float($value);
    }

    private function failCreateCases(): iterable
    {
        return [
            [-1, new PriceCannotBeLessThanZero()],
            [-0.009, new PriceCannotBeLessThanZero()],
        ];
    }

    /**
     * @dataProvider addTestDataProvider
     */
    public function testCanAdd(Price $initialPrice, float $addValue, Price $expectedResult): void
    {
        # with float
        $result = $initialPrice->add($addValue);

        self::assertNotSame($initialPrice, $result);
        self::assertTrue($result->eq($expectedResult));

        # with object
        $addValue = Price::float($addValue);
        $result = $initialPrice->add($addValue);

        self::assertNotSame($initialPrice, $result);
        self::assertTrue($result->eq($expectedResult));
    }

    private function addTestDataProvider(): iterable
    {
        yield [Price::float(123.45), 1, Price::float(124.45)];
        yield [Price::float(123.45), 1.002, Price::float(124.452)];
        yield [Price::float(123.45), 2.1, Price::float(125.55)];
        yield [Price::float(123.45), 2.101, Price::float(125.551)];
        yield [Price::float(123), 2.1, Price::float(125.1)];
        yield [Price::float(123), 2.101, Price::float(125.101)];
    }

    /**
     * @dataProvider subTestDataProvider
     */
    public function testCanSub(Price $initialPrice, Price|float $subValue, Price $expectedResult): void
    {
        # with float
        $result = $initialPrice->sub($subValue);

        self::assertNotSame($initialPrice, $result);
        self::assertTrue($result->eq($expectedResult));

        # with object
        $subValue = Price::float($subValue);
        $result = $initialPrice->sub($subValue);

        self::assertNotSame($initialPrice, $result);
        self::assertTrue($result->eq($expectedResult));
    }

    private function subTestDataProvider(): iterable
    {
        yield [Price::float(123.45), 1, Price::float(122.45)];
        yield [Price::float(123.452), 1.001, Price::float(122.451)];
        yield [Price::float(123.46), 2.1, Price::float(121.36)];
        yield [Price::float(123.46), 2.101, Price::float(121.359)];
        yield [Price::float(123), 2.1, Price::float(120.9)];
    }

    /**
     * @dataProvider greaterThanTestDataProvider
     */
    public function testGreaterThan(Price $a, Price $b, bool $expectedResult): void
    {
        self::assertSame($expectedResult, $a->greaterThan($b));
    }

    private function greaterThanTestDataProvider(): iterable
    {
        yield [Price::float(123), Price::float(456), false];
        yield [Price::float(455.999), Price::float(456), false];
        yield [Price::float(456), Price::float(456), false];
        yield [Price::float(456.001), Price::float(456), true];
    }

    /**
     * @dataProvider greaterOrEqualsTestDataProvider
     */
    public function testGreaterOrEquals(Price $a, Price $b, bool $expectedResult): void
    {
        self::assertSame($expectedResult, $a->greaterOrEquals($b));
    }

    private function greaterOrEqualsTestDataProvider(): iterable
    {
        yield [Price::float(123), Price::float(456), false];
        yield [Price::float(455.999), Price::float(456), false];
        yield [Price::float(456), Price::float(456), true];
        yield [Price::float(456.01), Price::float(456), true];
    }

    /**
     * @dataProvider lessThanTestDataProvider
     */
    public function testLessThan(Price $a, Price $b, bool $expectedResult): void
    {
        self::assertSame($expectedResult, $a->lessThan($b));
    }

    private function lessThanTestDataProvider(): iterable
    {
        yield [Price::float(456), Price::float(123), false];
        yield [Price::float(456), Price::float(455.999), false];
        yield [Price::float(456), Price::float(456), false];
        yield [Price::float(456), Price::float(456.001), true];
    }

    /**
     * @dataProvider lessOrEqualsTestDataProvider
     */
    public function testLessOrEquals(Price $a, Price $b, bool $expectedResult): void
    {
        self::assertSame($expectedResult, $a->lessOrEquals($b));
    }

    private function lessOrEqualsTestDataProvider(): iterable
    {
        yield [Price::float(456), Price::float(123), false];
        yield [Price::float(456), Price::float(455.999), false];
        yield [Price::float(456), Price::float(456), true];
        yield [Price::float(456), Price::float(456.001), true];
    }

    /**
     * @dataProvider priceIsOverTakeProfitTestCases
     */
    public function testIsPriceOverTakeProfit(Price $price, Side $positionSide, float $takeProfitPrice, bool $expectedResult): void
    {
        $result = $price->isPriceOverTakeProfit($positionSide, $takeProfitPrice);

        self::assertEquals($expectedResult, $result);
    }

    private function priceIsOverTakeProfitTestCases(): iterable
    {
        yield 'over SHORT TP (+)' => [
            'price' => Price::float(200500),
            'stop.positionSide' => Side::Sell,
            'takeProfit.price' => 200500,
            'expectedResult' => true,
        ];

        yield 'over SHORT TP (-)' => [
            'price' => Price::float(200500),
            'stop.positionSide' => Side::Sell,
            'takeProfit.price' => 200499,
            'expectedResult' => false,
        ];

        yield 'over LONG TP (+)' => [
            'price' => Price::float(200500),
            'stop.positionSide' => Side::Buy,
            'takeProfit.price' => 200500,
            'expectedResult' => true,
        ];

        yield 'over LONG TP (-)' => [
            'price' => Price::float(200500),
            'stop.positionSide' => Side::Buy,
            'takeProfit.price' => 200501,
            'expectedResult' => false,
        ];
    }

    /**
     * @dataProvider priceInLossOfOther
     */
    public function testIsPriceInLossOfOther(Price $price, Side $positionSide, float $takeProfitPrice, bool $expectedResult): void
    {
        $result = $price->isPriceInLossOfOther($positionSide, $takeProfitPrice);

        self::assertEquals($expectedResult, $result);
    }

    private function priceInLossOfOther(): iterable
    {
        yield 'over SHORT SL (+)' => [
            'price' => Price::float(200501.01),
            'stop.positionSide' => Side::Sell,
            'stop.price' => 200500,
            'expectedResult' => true,
        ];

        yield 'over SHORT SL (-)' => [
            'price' => Price::float(200501),
            'stop.positionSide' => Side::Sell,
            'stop.price' => 200501,
            'expectedResult' => false,
        ];

        yield 'over LONG SL (+)' => [
            'price' => Price::float(200500),
            'stop.positionSide' => Side::Buy,
            'stop.price' => 200500.01,
            'expectedResult' => true,
        ];

        yield 'over LONG SL (-)' => [
            'price' => Price::float(200500),
            'stop.positionSide' => Side::Buy,
            'stop.price' => 200500,
            'expectedResult' => false,
        ];
    }

    public function testDifferenceWith(): void
    {
        $currentPrice = Price::float(100500);
        $fromPrice = Price::float(200500);

        self::assertEquals(PriceMovement::fromToTarget($fromPrice, $currentPrice), $currentPrice->differenceWith($fromPrice));
    }

    /**
     * @dataProvider isPriceInRangeTestCases
     */
    public function testIsPriceInRange(float $price, PriceRange $priceRange, $expectedResult): void
    {
        $result = Price::float($price)->isPriceInRange($priceRange);

        self::assertEquals($expectedResult, $result);
    }

    public function isPriceInRangeTestCases(): array
    {
        return [
            [100500, PriceRange::create(100500, 200500), true],
            [200000, PriceRange::create(100500, 200500), true],
            [200500, PriceRange::create(100500, 200500), true],
            [100499, PriceRange::create(100500, 200500), false],
            [200501, PriceRange::create(100500, 200500), false],
        ];
    }

    public function testGetPnlInPercents(): void
    {
        ## SHORT
        $position = PositionFactory::short(Symbol::BTCUSDT, 30000, 1, 100);

        self::assertEquals(-120, Price::float(30360)->getPnlPercentFor($position));
        self::assertEquals(-20, Price::float(30060)->getPnlPercentFor($position));
        self::assertEquals(0, Price::float(30000)->getPnlPercentFor($position));
        self::assertEquals(20, Price::float(29940)->getPnlPercentFor($position));
        self::assertEquals(100, Price::float(29700)->getPnlPercentFor($position));

        ## LONG
        $position = PositionFactory::long(Symbol::BTCUSDT, 30000, 1, 100);

        self::assertEquals(120, Price::float(30360)->getPnlPercentFor($position));
        self::assertEquals(20, Price::float(30060)->getPnlPercentFor($position));
        self::assertEquals(0, Price::float(30000)->getPnlPercentFor($position));
        self::assertEquals(-20, Price::float(29940)->getPnlPercentFor($position));
        self::assertEquals(-100, Price::float(29700)->getPnlPercentFor($position));
    }

    public function testGetTargetPriceByPnlPercent(): void
    {
        ## SHORT
        $position = PositionFactory::short(Symbol::BTCUSDT, 100500, 1, 100);
        $price = Price::float(30000);

        self::assertEquals(Price::float(30360), $price->getTargetPriceByPnlPercent(-120, $position));
        self::assertEquals(Price::float(30300), $price->getTargetPriceByPnlPercent(-100, $position));
        self::assertEquals(Price::float(30060), $price->getTargetPriceByPnlPercent(-20, $position));
        self::assertEquals(Price::float(30000), $price->getTargetPriceByPnlPercent(0, $position));
        self::assertEquals(Price::float(29940), $price->getTargetPriceByPnlPercent(20, $position));
        self::assertEquals(Price::float(29700), $price->getTargetPriceByPnlPercent(100, $position));
        self::assertEquals(Price::float(29640), $price->getTargetPriceByPnlPercent(120, $position));

        ## LONG
        $position = PositionFactory::long(Symbol::BTCUSDT, 100500, 1, 100);

        self::assertEquals(Price::float(30360), $price->getTargetPriceByPnlPercent(120, $position));
        self::assertEquals(Price::float(30300), $price->getTargetPriceByPnlPercent(100, $position));
        self::assertEquals(Price::float(30060), $price->getTargetPriceByPnlPercent(20, $position));
        self::assertEquals(Price::float(30000), $price->getTargetPriceByPnlPercent(0, $position));
        self::assertEquals(Price::float(29940), $price->getTargetPriceByPnlPercent(-20, $position));
        self::assertEquals(Price::float(29700), $price->getTargetPriceByPnlPercent(-100, $position));
        self::assertEquals(Price::float(29640), $price->getTargetPriceByPnlPercent(-120, $position));
    }

    public function testModifyByDirection(): void
    {
        self::assertEquals(Price::float(50000.2), Price::float(50000)->modifyByDirection(Side::Sell, PriceMovementDirection::TO_LOSS, 0.2));
        self::assertEquals(Price::float(50000.2), Price::float(50000)->modifyByDirection(Side::Buy, PriceMovementDirection::TO_PROFIT, 0.2));

        self::assertEquals(Price::float(49999.8), Price::float(50000)->modifyByDirection(Side::Buy, PriceMovementDirection::TO_LOSS, 0.2));
        self::assertEquals(Price::float(49999.8), Price::float(50000)->modifyByDirection(Side::Sell, PriceMovementDirection::TO_PROFIT, 0.2));
    }
}
