<?php

declare(strict_types=1);

namespace App\Tests\Unit\Modules\Liquidation\Domain;

use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Price;
use App\Liquidation\Domain\Assert\LiquidationIsSafeAssertion;
use PHPUnit\Framework\TestCase;

final class LiquidationIsSafeAssertionTest extends TestCase
{
    /**
     * @dataProvider cases
     */
    public function testResult(Side $positionSide, float $liquidationPrice, float $tickerPrice, float $safeDistance, bool $expectedResult): void
    {
        $liquidationPrice = Price::float($liquidationPrice);
        $tickerPrice = Price::float($tickerPrice);

        self::assertEquals(
            $expectedResult,
            LiquidationIsSafeAssertion::assert($positionSide, $liquidationPrice, $tickerPrice, $safeDistance)
        );
    }

    public function cases(): array
    {
        return [
            [Side::Sell, 2500, 2000, 600, false],
            [Side::Sell, 2500, 2000, 500, true],
            [Side::Sell, 2500, 2000, 400, true],
            [Side::Sell, 2500, 2000, 3000, false],

            [Side::Buy, 1500, 2000, 600, false],
            [Side::Buy, 1500, 2000, 500, true],
            [Side::Buy, 1500, 2000, 400, true],
            [Side::Buy, 1500, 2000, 3000, false],

        ];
    }
}
