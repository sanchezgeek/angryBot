<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\UseCase\Trading\Sandbox;

use App\Application\UseCase\Trading\Sandbox\SandboxState;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\CoinAmount;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Factory\Position\PositionBuilder as PB;
use App\Tests\Factory\TickerFactory;
use PHPUnit\Framework\TestCase;

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
}