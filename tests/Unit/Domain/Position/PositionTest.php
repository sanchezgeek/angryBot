<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Position;

use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\CoinAmount;
use App\Domain\Order\Leverage;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Factory\PositionFactory;
use App\Tests\Factory\TickerFactory;
use LogicException;
use PHPUnit\Framework\TestCase;

use function sprintf;

/**
 * @covers \App\Bot\Domain\Position
 */
final class PositionTest extends TestCase
{
    public function testShortPosition(): void
    {
        $side = Side::Sell;
        $symbol = Symbol::BTCUSDT;
        $entry = 100500;
        $size = 1050.1;
        $value = 100005000;
        $liquidation = 200500;
        $initialMargin = 1000;
        $leverage = 100;

        $position = new Position($side, $symbol, $entry, $size, $value, $liquidation, $initialMargin, $leverage);

        self::assertEquals($side, $position->side);
        self::assertTrue($position->isShort());
        self::assertFalse($position->isLong());
        self::assertEquals($symbol, $position->symbol);
        self::assertEquals($entry, $position->entryPrice);
        self::assertEquals($size, $position->size);
        self::assertEquals($value, $position->value);
        self::assertEquals($liquidation, $position->liquidationPrice);
        self::assertEquals(new CoinAmount($symbol->associatedCoin(), $initialMargin), $position->initialMargin);
        self::assertEquals(new Leverage($leverage), $position->leverage);
    }

    public function testLongPosition(): void
    {
        $side = Side::Buy;
        $symbol = Symbol::BTCUSDT;
        $entry = 100500;
        $size = 1050;
        $value = 100005000;
        $liquidation = 90500;
        $initialMargin = 1000;
        $leverage = 100;
        $position = new Position($side, $symbol, $entry, $size, $value, $liquidation, $initialMargin, $leverage);

        self::assertEquals($side, $position->side);
        self::assertTrue($position->isLong());
        self::assertFalse($position->isShort());
        self::assertEquals($symbol, $position->symbol);
        self::assertEquals($entry, $position->entryPrice);
        self::assertEquals($size, $position->size);
        self::assertEquals($value, $position->value);
        self::assertEquals($liquidation, $position->liquidationPrice);
        self::assertEquals(new CoinAmount($symbol->associatedCoin(), $initialMargin), $position->initialMargin);
        self::assertEquals(new Leverage($leverage), $position->leverage);
    }

    /**
     * @dataProvider successCasesProvider
     */
    public function testCanGetVolumePart(Position $position, float $volumePart, float $expectedVolume): void
    {
        self::assertEquals($expectedVolume, $position->getVolumePart($volumePart));
    }

    private function successCasesProvider(): array
    {
        return [
            [
                '$position' => PositionFactory::short(Symbol::BTCUSDT, 30000, 0.5, 100),
                '$volumePart' => 50,
                'expectedVolume' => 0.25,
            ],
            [
                '$position' => PositionFactory::short(Symbol::BTCUSDT, 30000, 0.1, 100),
                '$volumePart' => 10,
                'expectedVolume' => 0.01,
            ],
            [
                '$position' => PositionFactory::short(Symbol::BTCUSDT, 30000, 0.1, 100),
                '$volumePart' => 3,
                'expectedVolume' => 0.003,
            ],
            [
                '$position' => PositionFactory::short(Symbol::BTCUSDT, 29000, 0.5, 100),
                '$volumePart' => 10,
                'expectedVolume' => 0.05,
            ],
        ];
    }

    /**
     * @dataProvider wrongGetVolumeCasesProvider
     */
    public function testFailGetVolumePart(float $percent): void
    {
        $position = PositionFactory::short(Symbol::BTCUSDT, 30000, 0.5, 100);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(sprintf('Percent value must be in 0..100 range. "%.2f" given.', $percent));

        $position->getVolumePart($percent);
    }

    private function wrongGetVolumeCasesProvider(): array
    {
        return [[-150], [-100], [0], [101], [150]];
    }

    public function testCanGetDeltaToLiquidation(): void
    {
        $position = PositionFactory::short(Symbol::BTCUSDT, 30000, 0.5, 100, 31000);
        $ticker = new Ticker(Symbol::BTCUSDT, 30450, 30600, 'test');

        self::assertEquals(550, $position->priceDeltaToLiquidation($ticker));
    }

    public function testFailGetDeltaToLiquidation(): void
    {
        $position = PositionFactory::short(Symbol::BTCUSDT, 30000, 0.5, 100, 31000);
        $ticker = new Ticker(Symbol::BTCUSD, 30450, 30600, 'test');

        self::expectException(LogicException::class);
        self::expectExceptionMessage(sprintf('invalid ticker "%s" provided ("%s" expected)', $ticker->symbol->name, $position->symbol->name));

        $position->priceDeltaToLiquidation($ticker);
    }
}
