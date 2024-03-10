<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Price\Helper;

use App\Domain\Price\Helper\PriceHelper;
use App\Domain\Price\Price;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Domain\Price\Helper\PriceHelper
 */
final class PriceHelperTest extends TestCase
{
    public function testRound(): void
    {
        self::assertEquals(456, PriceHelper::round(455.999));
        self::assertEquals(456, PriceHelper::round(456.001));
        self::assertEquals(456.01, PriceHelper::round(456.009));
        self::assertEquals(456.01, PriceHelper::round(456.01));
        self::assertEquals(456.01, PriceHelper::round(456.011));
        self::assertEquals(456.46, PriceHelper::round(456.456));
        self::assertEquals(456.46, PriceHelper::round(456.4567));
        self::assertEquals(1000, PriceHelper::round(999.999));
    }

    /**
     * @dataProvider maxTestDataProvider
     */
    public function testMax(float $a, float $b, float $expectedResult): void
    {
        $a = Price::float($a);
        $b = Price::float($b);
        $expectedResult = Price::float($expectedResult);

        self::assertEquals(PriceHelper::max($a, $b), $expectedResult);
        self::assertEquals(PriceHelper::max($b, $a), $expectedResult);
    }

    public function maxTestDataProvider(): array
    {
        return [
            [49000, 50001, 50001],
            [50000, 50001, 50001],
            [50000, 50000, 50000],

            [50000.1, 50001, 50001],
            [50000.1, 50001.1, 50001.1],
            [50000.1, 50000.1, 50000.1],
        ];
    }

    /**
     * @dataProvider minTestDataProvider
     */
    public function testMin(float $a, float $b, float $expectedResult): void
    {
        $a = Price::float($a);
        $b = Price::float($b);
        $expectedResult = Price::float($expectedResult);

        self::assertEquals(PriceHelper::min($a, $b), $expectedResult);
        self::assertEquals(PriceHelper::min($b, $a), $expectedResult);
    }

    public function minTestDataProvider(): array
    {
        return [
            [49000, 50000, 49000],
            [50000, 50001, 50000],
            [50000, 50000, 50000],

            [50000, 50001.1, 50000],
            [50000.1, 50001, 50000.1],
            [50000.1, 50001.1, 50000.1],
            [50000.1, 50000.1, 50000.1],
            [50000.1, 49000.1, 49000.1],
        ];
    }
}
