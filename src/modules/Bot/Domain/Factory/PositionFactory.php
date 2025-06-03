<?php

declare(strict_types=1);

namespace App\Bot\Domain\Factory;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;

final class PositionFactory
{
    public static function fakeWithNoLiquidation(SymbolInterface $symbol, Side $positionSide, SymbolPrice|float $entryPrice, int $leverage = 100): Position
    {
        $entryPrice = SymbolPrice::toFloat($entryPrice);

        $size = $symbol->minOrderQty();
        $positionValue = $entryPrice * $size;
        $initialMargin = $positionValue / $leverage;

        return new Position(
            $positionSide,
            $symbol,
            $entryPrice,
            $size,
            $positionValue,
            0,
            $initialMargin,
            $leverage
        );
    }
}
