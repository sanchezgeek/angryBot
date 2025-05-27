<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tests\Factory;

use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Price\SymbolPrice;
use App\Tests\Factory\TickerFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Tests\Factory\TickerFactory
 */
class TickerFactoryTest extends TestCase
{
    public function testFactory(): void
    {
        $symbol = Symbol::BTCUSDT;

        $ticker = TickerFactory::create($symbol, 100500, 100600, 100700);

        self::assertEquals(new Ticker($symbol, 100600, 100500, 100700), $ticker);
    }
}
