<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tests\Factory;

use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Price\Price;
use App\Tests\Factory\TickerFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Tests\Factory\TickerFactory
 */
class TickerFactoryTest extends TestCase
{
    public function testFactory(): void
    {
        $ticker = TickerFactory::create(Symbol::BTCUSDT, 100500, 100600, 100700);

        self::assertEquals(
            new Ticker(Symbol::BTCUSDT, Price::float(100600), 100500, Price::float(100700)),
            $ticker
        );
    }
}