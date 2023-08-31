<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Shared\ValueObject;

use App\Domain\Shared\Helper\PriceHelper;
use App\Domain\Shared\ValueObject\Price;
use DomainException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Domain\Shared\ValueObject\Price
 */
final class PriceTest extends TestCase
{
    /**
     * @dataProvider positiveCases
     */
    public function testCanCreate(float $value, Price $expectedResult): void
    {
        $price = Price::float($value);

        self::assertSame(PriceHelper::round($value), $price->value());
        self::assertTrue($price->eq($expectedResult), $price->value() . ' = ' . $expectedResult->value());
    }

    private function positiveCases(): iterable
    {
        return [
            [1, Price::float(1)],
            [0.01, Price::float(0.01)],
            [1.01, Price::float(1.01)],
            [1.001, Price::float(1)],
            [999.99, Price::float(999.99)],
            [999.999, Price::float(1000)],
        ];
    }

    /**
     * @dataProvider createNegativeCases
     */
    public function testFailCreate(float $value): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Price cannot be less or equals zero');

        Price::float($value);
    }

    private function createNegativeCases(): iterable
    {
        return [[-1], [-0.009], [0], [0.001]];
    }

    /**
     * @dataProvider addTestDataProvider
     */
    public function testCanAdd(float $initialPriceValue, float $subValue, Price $expectedResult): void
    {
        $initial = Price::float($initialPriceValue);

        $result = $initial->add($subValue);

        self::assertNotSame($initial, $result);
        self::assertTrue($result->eq($expectedResult));
    }

    private function addTestDataProvider(): iterable
    {
        yield [123.45, 1, Price::float(124.45)];
        yield [123.45, 2.1, Price::float(125.55)];
        yield [123, 2.1, Price::float(125.1)];
        yield [1.1, 2.11, Price::float(3.21)];
    }

    /**
     * @dataProvider subTestDataProvider
     */
    public function testCanSub(float $initialPriceValue, float $subValue, Price $expectedResult): void
    {
        $initial = Price::float($initialPriceValue);

        $result = $initial->sub($subValue);

        self::assertNotSame($initial, $result);
        self::assertTrue($result->eq($expectedResult));
    }

    /**
     * @dataProvider greaterThanTestDataProvider
     */
    public function testGreaterThan(Price $a, Price $b, bool $expectedResult): void
    {
        self::assertSame($expectedResult, $a->greater($b));
    }

    private function greaterThanTestDataProvider(): iterable
    {
        yield [Price::float(123), Price::float(456), false];
        yield [Price::float(455.999), Price::float(456), false];
        yield [Price::float(456.01), Price::float(456), true];
    }

    private function subTestDataProvider(): iterable
    {
        yield [123.45, 1, Price::float(122.45)];
        yield [123.45, 2.1, Price::float(121.35)];
        yield [123, 2.1, Price::float(120.9)];
    }
}
