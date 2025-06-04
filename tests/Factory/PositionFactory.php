<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Bot\Domain\Position;
use App\Domain\Position\ValueObject\Side;
use App\Trading\Domain\Symbol\SymbolInterface;

/**
 * @see \App\Tests\Unit\Tests\Factory\PositionFactoryTest
 */
final class PositionFactory
{
    private const int DEFAULT_PRICE = 29000;
    private const float DEFAULT_SIZE = 0.05;
    private const int DEFAULT_LEVERAGE = 100;

    public static function short(
        SymbolInterface $symbol,
        float $at = self::DEFAULT_PRICE,
        float $size = self::DEFAULT_SIZE,
        int $leverage = self::DEFAULT_LEVERAGE,
        ?float $liquidationPrice = null,
    ): Position {
        $positionValue = $at * $size;
        $liquidationPrice = ($liquidationPrice !== null) ? $liquidationPrice : $at + 1000; // @todo calc

        $im = $positionValue / $leverage;

        return new Position(
            Side::Sell,
            $symbol,
            $at,
            $size,
            $positionValue,
            $liquidationPrice,
            $im,
            $leverage
        );
    }

    public static function long(
        SymbolInterface $symbol,
        float $at = self::DEFAULT_PRICE,
        float $size = self::DEFAULT_SIZE,
        int $leverage = self::DEFAULT_LEVERAGE,
        ?float $liquidationPrice = null,
    ): Position {
        $positionValue = $at * $size;
        $liquidationPrice = ($liquidationPrice !== null) ? $liquidationPrice : $at - 1000; // @todo calc
        $im = $positionValue / $leverage;

        return new Position(
            Side::Buy,
            $symbol,
            $at,
            $size,
            $positionValue,
            $liquidationPrice,
            $im,
            $leverage
        );
    }
}
