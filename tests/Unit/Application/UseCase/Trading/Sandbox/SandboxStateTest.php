<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\UseCase\Trading\Sandbox;

use App\Application\UseCase\Trading\Sandbox\Dto\ClosedPosition;
use App\Application\UseCase\Trading\Sandbox\SandboxState;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\CoinAmount;
use App\Domain\Position\Helper\PositionClone;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Factory\Position\PositionBuilder as PB;
use App\Tests\Factory\TickerFactory;
use PHPUnit\Framework\TestCase;

/**
 * @group sandbox
 *
 * @covers \App\Application\UseCase\Trading\Sandbox\SandboxState
 */
class SandboxStateTest extends TestCase
{
    public function testCreate(): void
    {
        $symbol = Symbol::BTCUSDT;
        $coin = $symbol->associatedCoin();

        $ticker = TickerFactory::withEqualPrices($symbol, 68150);
        $free = new CoinAmount($coin, 98.1001);
        $long = PB::long()->entry(59426.560)->size(0.084)->build();
        $short = PB::short()->entry(67533.430)->size(0.188)->liq(75361.600)->opposite($long)->build();

        $expectedAvailable = new CoinAmount($coin, 33.9768);

        // Act
        $state = new SandboxState($ticker, $free, $long, $short);

        // Assert
        self::assertEquals($short, $state->getPosition(Side::Sell));
        self::assertEquals($long, $state->getPosition(Side::Buy));
        self::assertEquals($free, $state->getFreeBalance());
        self::assertEquals($expectedAvailable, $state->getAvailableBalance());
    }

    /**
     * @dataProvider allFreeIsAvailableTestCases
     */
    public function testAllFreeAvailable(Ticker $ticker, float $free, array $positions, float $expectedAvailable): void
    {
        $state = new SandboxState($ticker, new CoinAmount($ticker->symbol->associatedCoin(), $free), ...$positions);

        self::assertEquals(new CoinAmount($ticker->symbol->associatedCoin(), $expectedAvailable), $state->getAvailableBalance());
    }

    public function allFreeIsAvailableTestCases(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $ticker = TickerFactory::withEqualPrices($symbol, 68150);
        $free = 98.1001;

        # available === free
        $expectedAvailable = $free;

        $positions = [];
        yield 'when no positions opened' => [$ticker, $free, $positions, $expectedAvailable];

        $positions = [PB::long()->entry(59426.560)->size(0.084)->build()];
        yield 'LONG in profit' => [$ticker, $free, $positions, $expectedAvailable];

        $positions = [PB::short()->entry(69426.560)->size(0.084)->build()];
        yield 'SHORT in profit' => [$ticker, $free, $positions, $expectedAvailable];

        # One-way mode
        $positions = [PB::short()->entry(68000)->size(0.1)->build()];
        $expectedAvailable = 83.1001;
        yield 'SHORT in loss' => [$ticker, $free, $positions, $expectedAvailable];

        $positions = [PB::long()->entry(68250)->size(0.1)->build()];
        $expectedAvailable = 88.1001;
        yield 'LONG in loss' => [$ticker, $free, $positions, $expectedAvailable];

        # HEDGE (mainPosition = SHORT)
        $long = PB::long()->entry(59426.560)->size(0.084)->build();
        $short = PB::short()->entry(69533.430)->size(0.188)->liq(75361.600)->opposite($long)->build();
        $positions = [$long, $short];
        $expectedAvailable = $free;
        yield '[hedge] main position in profit' => [$ticker, $free, $positions, $expectedAvailable];

        $long = PB::long()->entry(59426.560)->size(0.084)->build();
        $short = PB::short()->entry(67533.430)->size(0.188)->liq(75361.600)->opposite($long)->build();
        $positions = [$long, $short];
        $expectedAvailable = 33.9768;
        yield '[hedge] main position is loss' => [$ticker, $free, $positions, $expectedAvailable];
    }

    public function testSetClosedPosition(): void
    {
        $symbol = Symbol::BTCUSDT;
        $coin = $symbol->associatedCoin();

        $ticker = TickerFactory::withEqualPrices($symbol, 68150);
        $free = new CoinAmount($coin, 98.1001);
        $long = PB::long()->entry(59426.560)->size(0.084)->build();
        $short = PB::short()->entry(67533.430)->size(0.188)->liq(75361.600)->opposite($long)->build();

        $state = new SandboxState($ticker, $free, $long, $short);

        // Act
        $state->setPositionAndActualizeOpposite(new ClosedPosition(Side::Sell, $symbol));

        // Assert
        self::assertEquals(null, $state->getPosition(Side::Sell));
        self::assertEquals(PositionClone::clean($long)->create(), $state->getPosition(Side::Buy));
    }
}