<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\UseCase\CalcPositionLiquidationPrice;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceResult;
use App\Domain\Price\Price;
use PHPUnit\Framework\TestCase;

final class CalcPositionLiquidationPriceResultTest extends TestCase
{
    public function testGetLiquidationPrice(): void
    {
        $result = new CalcPositionLiquidationPriceResult(Price::float(35000));

        self::assertEquals(Price::float(35000), $result->estimatedLiquidationPrice());
    }
}
