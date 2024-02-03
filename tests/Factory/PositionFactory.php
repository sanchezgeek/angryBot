<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;

final class PositionFactory
{
    private const DEFAULT_PRICE = 29000;
    private const DEFAULT_SIZE = 1;
    private const DEFAULT_LEVERAGE = 100;

    public static function short(
        Symbol $symbol,
        float $at = self::DEFAULT_PRICE,
        float $size = self::DEFAULT_SIZE,
        int $leverage = self::DEFAULT_LEVERAGE,
        ?float $liquidationPrice = null,
    ): Position {
        $positionValue = $at * $size;
        $liquidationPrice = $liquidationPrice ?: $at + 1000;

        return new Position(
            Side::Sell,
            $symbol,
            $at,
            $size,
            $positionValue,
            $liquidationPrice,
            $positionValue / $leverage,
            $leverage
        );
    }

    public static function long(
        Symbol $symbol,
        float $at = self::DEFAULT_PRICE,
        float $size = self::DEFAULT_SIZE,
        int $leverage = self::DEFAULT_LEVERAGE,
        ?float $liquidationPrice = null,
    ): Position {
        $positionValue = $at * $size;
        $liquidationPrice = $at - 1000; // @todo calc

        return new Position(
            Side::Buy,
            $symbol,
            $at,
            $size,
            $positionValue,
            $liquidationPrice,
            $positionValue / $leverage,
            $leverage
        );
    }
}
