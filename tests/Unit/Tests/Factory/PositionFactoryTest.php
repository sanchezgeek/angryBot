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

        $position = PositionFactory::short($symbol, $entry, $size, $leverage, $liquidation);

        self::assertEquals(
            new Position(Side::Sell, $symbol, $entry, $size, 15000, 31000, 30000 / $leverage * $size, $leverage),
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

        $position = PositionFactory::short($symbol, $entry, $size, $leverage);

        self::assertEquals($symbol, $position->symbol);
        self::assertEquals(Side::Sell, $position->side);
        self::assertEquals($entry, $position->entryPrice);
        self::assertEquals($size, $position->size);
        self::assertEquals($size * $entry, $position->value);
        self::assertEquals($expectedLiquidation, $position->liquidationPrice);
        self::assertEquals(new Leverage($leverage), $position->leverage);
        self::assertEquals(new CoinAmount($symbol->associatedCoin(), $size * $entry / $leverage), $position->initialMargin);
    }

    public function testLongFactoryWithDefaultValues(): void
    {
        $symbol = Symbol::BTCUSDT;

        $entry = 30000;
        $size = 0.5;
        $leverage = 100;
        $expectedLiquidation = $entry - 1000;

        $position = PositionFactory::long($symbol, $entry, $size, $leverage);

        self::assertEquals($symbol, $position->symbol);
        self::assertEquals(Side::Buy, $position->side);
        self::assertEquals($entry, $position->entryPrice);
        self::assertEquals($size, $position->size);
        self::assertEquals($size * $entry, $position->value);
        self::assertEquals($expectedLiquidation, $position->liquidationPrice);
        self::assertEquals(new Leverage($leverage), $position->leverage);
        self::assertEquals(new CoinAmount($symbol->associatedCoin(), $size * $entry / $leverage), $position->initialMargin);
    }
}
