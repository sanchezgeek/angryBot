<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tests\Factory;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\CoinAmount;
use App\Domain\Position\ValueObject\Leverage;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Factory\PositionFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Tests\Factory\PositionFactory
 */
final class PositionFactoryTest extends TestCase
{
    public function testShortFactory(): void
    {
        $symbol = Symbol::BTCUSDT;

        $entry = 30000;
        $size = 0.5;
        $leverage = 100;
        $liquidation = 31000;
        $expectedMargin = $entry / $leverage * $size;

        // Act
        $position = PositionFactory::short($symbol, $entry, $size, $leverage, $liquidation);

        // Assert
        self::assertEquals(
            new Position(Side::Sell, $symbol, $entry, $size, 15000, $liquidation, $expectedMargin, $leverage),
            $position
        );
    }

    public function testShortFactoryWithDefaultValues(): void
    {
        $symbol = Symbol::BTCUSDT;

        $entry = 30000;
        $size = 0.5;
        $leverage = 100;
        $expectedLiquidation = $entry + 1000;
        $expectedMargin = $size * $entry / $leverage;

        // Act
        $position = PositionFactory::short($symbol, $entry, $size, $leverage);

        // Assert
        self::assertEquals($symbol, $position->symbol);
        self::assertEquals(Side::Sell, $position->side);
        self::assertEquals($entry, $position->entryPrice);
        self::assertEquals($size, $position->size);
        self::assertEquals($size * $entry, $position->value);
        self::assertEquals($expectedLiquidation, $position->liquidationPrice);
        self::assertEquals(new Leverage($leverage), $position->leverage);
        self::assertEquals($symbol->associatedCoinAmount($expectedMargin), $position->initialMargin);
    }

    public function testLongFactoryWithDefaultValues(): void
    {
        $symbol = Symbol::BTCUSDT;

        $entry = 30000;
        $size = 0.5;
        $leverage = 100;
        $expectedLiquidation = $entry - 1000;
        $expectedMargin = $size * $entry / $leverage;

        // Act
        $position = PositionFactory::long($symbol, $entry, $size, $leverage);

        // Assert
        self::assertEquals($symbol, $position->symbol);
        self::assertEquals(Side::Buy, $position->side);
        self::assertEquals($entry, $position->entryPrice);
        self::assertEquals($size, $position->size);
        self::assertEquals($size * $entry, $position->value);
        self::assertEquals($expectedLiquidation, $position->liquidationPrice);
        self::assertEquals(new Leverage($leverage), $position->leverage);
        self::assertEquals($symbol->associatedCoinAmount($expectedMargin), $position->initialMargin);
    }
}
