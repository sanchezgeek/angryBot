<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Order;

use App\Domain\Order\Order;
use App\Domain\Price\Price;
use PHPUnit\Framework\TestCase;

/**
 * @covers Order
 */
final class OrderTest extends TestCase
{
    public function testCanCreate(): void
    {
        $order = new Order(Price::float(30000), 0.1);

        self::assertEquals(Price::float(30000), $order->price());
        self::assertEquals(0.1, $order->volume());
    }
}
