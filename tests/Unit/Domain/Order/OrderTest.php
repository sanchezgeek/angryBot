<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Order;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Order;
use App\Domain\Price\SymbolPrice;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Domain\Order\Order
 */
final class OrderTest extends TestCase
{
    public function testCanCreate(): void
    {
        $symbol = Symbol::BTCUSDT;

        $order = new Order($symbol->makePrice(30000), 0.1);

        self::assertEquals($symbol->makePrice(30000), $order->price());
        self::assertEquals(0.1, $order->volume());
    }
}
