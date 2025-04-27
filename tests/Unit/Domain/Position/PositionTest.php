<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Position;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\CoinAmount;
use App\Domain\Position\Exception\SizeCannotBeLessOrEqualsZeroException;
use App\Domain\Position\ValueObject\Leverage;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Price;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Factory\PositionFactory;
use App\Tests\Factory\TickerFactory;
use App\Tests\Mixin\DataProvider\PositionSideAwareTest;
use LogicException;
use PHPUnit\Framework\TestCase;

use function sprintf;

/**
 * @covers \App\Bot\Domain\Position
 */
final class PositionTest extends TestCase
{
    use PositionSideAwareTest;

    /**
     * @dataProvider positionSideProvider
     */
    public function testFailCreateWithInvalidSize(Side $side): void
    {
        $size = 0;

        self::expectExceptionObject(new SizeCannotBeLessOrEqualsZeroException($size));

        PositionBuilder::bySide($side)->size($size)->build();
    }

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
        self::assertEquals($symbol->associatedCoinAmount($initialMargin), $position->initialMargin);
        self::assertEquals(new Leverage($leverage), $position->leverage);
        self::assertNull($position->oppositePosition);
        self::assertNull($position->getHedge());
        self::assertFalse($position->isMainPosition());
        self::assertFalse($position->isSupportPosition());
    }

    public function testShortPositionWithOpposite(): void
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
        $oppositePosition = new Position($side->getOpposite(), $symbol, 200500, 2050.1, 2000050000, 300500, 100, 100, 100);
        $position->setOppositePosition($oppositePosition);

        self::assertEquals($side, $position->side);
        self::assertTrue($position->isShort());
        self::assertFalse($position->isLong());
        self::assertEquals($symbol, $position->symbol);
        self::assertEquals($entry, $position->entryPrice);
        self::assertEquals($size, $position->size);
        self::assertEquals($value, $position->value);
        self::assertEquals($liquidation, $position->liquidationPrice);
        self::assertEquals($symbol->associatedCoinAmount($initialMargin), $position->initialMargin);
        self::assertEquals(new Leverage($leverage), $position->leverage);
        self::assertSame($oppositePosition, $position->oppositePosition);
        self::assertNotNull($position->getHedge());
        self::assertFalse($position->isMainPosition());
        self::assertTrue($position->isSupportPosition());
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
        self::assertEquals($symbol->associatedCoinAmount($initialMargin), $position->initialMargin);
        self::assertEquals(new Leverage($leverage), $position->leverage);
        self::assertNull($position->oppositePosition);
        self::assertNull($position->getHedge());
        self::assertFalse($position->isMainPosition());
        self::assertFalse($position->isSupportPosition());
    }

    public function testLongPositionWithOpposite(): void
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
        $oppositePosition = new Position($side->getOpposite(), $symbol, 200500, 1000, 100000, 300500, 100, 100, 100);
        $position->setOppositePosition($oppositePosition);

        self::assertEquals($side, $position->side);
        self::assertTrue($position->isLong());
        self::assertFalse($position->isShort());
        self::assertEquals($symbol, $position->symbol);
        self::assertEquals($entry, $position->entryPrice);
        self::assertEquals($size, $position->size);
        self::assertEquals($value, $position->value);
        self::assertEquals($liquidation, $position->liquidationPrice);
        self::assertEquals($symbol->associatedCoinAmount($initialMargin), $position->initialMargin);
        self::assertEquals(new Leverage($leverage), $position->leverage);
        self::assertSame($oppositePosition, $position->oppositePosition);
        self::assertNotNull($position->getHedge());
        self::assertTrue($position->isMainPosition());
        self::assertFalse($position->isSupportPosition());
    }

    /**
     * @dataProvider getVolumePartSuccessCases
     */
    public function testCanGetVolumePart(Position $position, float $volumePart, float $expectedVolume): void
    {
        self::assertEquals($expectedVolume, $position->getVolumePart($volumePart));
    }

    private function getVolumePartSuccessCases(): array
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
     * @dataProvider getVolumePartFailCases
     */
    public function testFailGetVolumePart(float $percent): void
    {
        $position = PositionFactory::short(Symbol::BTCUSDT, 30000, 0.5, 100);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(sprintf('Percent value must be in 0..100 range. "%.2f" given.', $percent));

        $position->getVolumePart($percent);
    }

    private function getVolumePartFailCases(): array
    {
        return [[-150], [-100], [0], [101], [150]];
    }

    public function testCanGetDeltaToLiquidation(): void
    {
        $position = PositionFactory::short(Symbol::BTCUSDT, 30000, 0.5, 100, 31000);

        $ticker = TickerFactory::create(Symbol::BTCUSDT, 30600,30450);
        self::assertEquals(550, $position->priceDistanceWithLiquidation($ticker));

        $ticker = TickerFactory::create(Symbol::BTCUSDT, 30600,31450);
        self::assertEquals(450, $position->priceDistanceWithLiquidation($ticker));
    }

    public function testFailGetDeltaToLiquidation(): void
    {
        $position = PositionFactory::short(Symbol::BTCUSDT, 30000, 0.5, 100, 31000);
        $ticker = TickerFactory::create(Symbol::BTCUSD, 30600,30450);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(sprintf('invalid ticker "%s" provided ("%s" expected)', $ticker->symbol->value, $position->symbol->value));

        $position->priceDistanceWithLiquidation($ticker);
    }

    /**
     * @dataProvider isPositionInProfitTestDataProvider
     */
    public function testIsPositionInProfit(Position $position, float $currentPrice, bool $expectedResult): void
    {
        self::assertEquals($expectedResult, $position->isPositionInProfit($currentPrice));
        self::assertEquals($expectedResult, $position->isPositionInProfit(Price::float($currentPrice)));
    }

    public function isPositionInProfitTestDataProvider(): iterable
    {
        $position = PositionFactory::short(Symbol::BTCUSDT, 30000, 0.5, 100, 31000);

        yield ['position' => $position, 'currentPrice' => 29999, 'expectedResult' => true];
        yield ['position' => $position, 'currentPrice' => 30000, 'expectedResult' => false];
        yield ['position' => $position, 'currentPrice' => 30001, 'expectedResult' => false];

        $position = PositionFactory::long(Symbol::BTCUSDT, 30000, 0.5, 100, 31000);

        yield ['position' => $position, 'currentPrice' => 29999, 'expectedResult' => false];
        yield ['position' => $position, 'currentPrice' => 30000, 'expectedResult' => false];
        yield ['position' => $position, 'currentPrice' => 30001, 'expectedResult' => true];
    }

    /**
     * @dataProvider isPositionInLossTestDataProvider
     */
    public function testIsPositionInLoss(Position $position, float $currentPrice, bool $expectedResult): void
    {
        self::assertEquals($expectedResult, $position->isPositionInLoss($currentPrice));
        self::assertEquals($expectedResult, $position->isPositionInLoss(Price::float($currentPrice)));
    }

    public function isPositionInLossTestDataProvider(): iterable
    {
        $position = PositionFactory::short(Symbol::BTCUSDT, 30000, 0.5, 100, 31000);

        yield ['position' => $position, 'currentPrice' => 29999, 'expectedResult' => false];
        yield ['position' => $position, 'currentPrice' => 30000, 'expectedResult' => false];
        yield ['position' => $position, 'currentPrice' => 30001, 'expectedResult' => true];

        $position = PositionFactory::long(Symbol::BTCUSDT, 30000, 0.5, 100, 31000);

        yield ['position' => $position, 'currentPrice' => 29999, 'expectedResult' => true];
        yield ['position' => $position, 'currentPrice' => 30000, 'expectedResult' => false];
        yield ['position' => $position, 'currentPrice' => 30001, 'expectedResult' => false];
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testLiquidationPrice(Side $side): void
    {
        $position = new Position($side, Symbol::BTCUSDT, 50000, 0.1, 5000, 51000.001, 50, 100);

        self::assertEquals(Price::float(51000.001), $position->liquidationPrice());
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testLiquidationDistance(Side $side): void
    {
        $position = new Position($side, Symbol::BTCUSDT, 50000, 0.1, 5000, 51000.01, 50, 100);
        self::assertEquals(1000.01, $position->liquidationDistance());

        $position = new Position($side, Symbol::BTCUSDT, 51000.01, 0.1, 5000, 50000, 50, 100);
        self::assertEquals(1000.01, $position->liquidationDistance());
    }
}
