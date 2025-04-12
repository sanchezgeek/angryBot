<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Order\Collection;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Collection\OrdersCollection;
use App\Domain\Order\Collection\OrdersLimitedWithMaxVolume;
use App\Domain\Order\Order;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Domain\Order\Collection\OrdersLimitedWithMaxVolume
 */
final class OrdersLimitedWithMaxVolumeTest extends TestCase
{
    /**
     * @dataProvider testDataProvider
     */
    public function testIterateOrders(
        Symbol $symbol,
        float $maxVolume,
        array $sourceOrders,
        array $expectedOrders,
    ): void {
        $collection = new OrdersLimitedWithMaxVolume(new OrdersCollection(...$sourceOrders), $maxVolume);

        self::assertEquals($expectedOrders, iterator_to_array($collection));
        self::assertEquals($expectedOrders, $collection->getOrders());
        self::assertEquals(count($expectedOrders), $collection->count());
    }

    private function testDataProvider(): array
    {
        $symbol = Symbol::ARCUSDT;

        return [
            [
                $symbol,
                273,
                [
                    new Order($symbol->makePrice(0.04565), 110),
                    new Order($symbol->makePrice(0.04595), 120),
                    new Order($symbol->makePrice(0.04625), 130),
                    new Order($symbol->makePrice(0.04655), 140),
                    new Order($symbol->makePrice(0.04685), 150),
                    new Order($symbol->makePrice(0.04715), 160),
                    new Order($symbol->makePrice(0.04745), 10),
                    new Order($symbol->makePrice(0.04775), 10),
                    new Order($symbol->makePrice(0.04805), 10),
                    new Order($symbol->makePrice(0.04835), 10),
                ],
                [
                    new Order($symbol->makePrice(0.04565), 110),
                    new Order($symbol->makePrice(0.04595), 163),
                ],
            ],
            [
                $symbol,
                233,
                [
                    new Order($symbol->makePrice(0.04565), 110),
                    new Order($symbol->makePrice(0.04595), 120),
                    new Order($symbol->makePrice(0.04625), 130),
                    new Order($symbol->makePrice(0.04655), 140),
                    new Order($symbol->makePrice(0.04685), 150),
                    new Order($symbol->makePrice(0.04715), 160),
                    new Order($symbol->makePrice(0.04745), 10),
                    new Order($symbol->makePrice(0.04775), 10),
                    new Order($symbol->makePrice(0.04805), 10),
                    new Order($symbol->makePrice(0.04835), 10),
                ],
                [
                    new Order($symbol->makePrice(0.04565), 110),
                    new Order($symbol->makePrice(0.04595), 123),
                ],
            ],
            [
                $symbol,
                168,
                [
                    new Order($symbol->makePrice(0.04565), 110),
                    new Order($symbol->makePrice(0.04595), 120),
                    new Order($symbol->makePrice(0.04625), 130),
                    new Order($symbol->makePrice(0.04655), 140),
                    new Order($symbol->makePrice(0.04685), 150),
                    new Order($symbol->makePrice(0.04715), 160),
                    new Order($symbol->makePrice(0.04745), 10),
                    new Order($symbol->makePrice(0.04775), 10),
                    new Order($symbol->makePrice(0.04805), 10),
                    new Order($symbol->makePrice(0.04835), 10),
                ],
                [
                    new Order($symbol->makePrice(0.04565), 168),
                ],
            ],
        ];
    }
}
