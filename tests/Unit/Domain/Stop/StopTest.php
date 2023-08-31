<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Stop;

use App\Bot\Domain\ValueObject\Symbol;
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\PositionFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers Stop
 */
final class StopTest extends TestCase
{
    public function testPnl(): void
    {
        $position = PositionFactory::short(Symbol::BTCUSDT, 30000, 1, 100);

        $stop = StopBuilder::short(1, 29700, 0.5)->build();

        self::assertEquals(100, $stop->getPnlInPercents($position));
        self::assertEquals(150, $stop->getPnlUsd($position));
    }

    public function testDummy(): void
    {
        self::markTestIncomplete('@todo | Should be tested later!');
    }
}
