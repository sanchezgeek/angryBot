<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tests\Factory;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Factory\PositionFactory;
use PHPUnit\Framework\TestCase;

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
}
