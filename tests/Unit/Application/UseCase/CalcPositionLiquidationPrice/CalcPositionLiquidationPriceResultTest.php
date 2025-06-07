<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\UseCase\CalcPositionLiquidationPrice;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceResult;
use App\Bot\Domain\ValueObject\SymbolEnum;
use PHPUnit\Framework\TestCase;

final class CalcPositionLiquidationPriceResultTest extends TestCase
{
    public function testGetLiquidationPrice(): void
    {
        $symbol = SymbolEnum::BTCUSDT;

        $result = new CalcPositionLiquidationPriceResult($symbol->makePrice(35000), $symbol->makePrice(36000));

        self::assertEquals(36000, $result->estimatedLiquidationPrice()->value());
        self::assertEquals(1000, $result->liquidationDistance());
    }
}
