<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Order\Collection;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Order\Collection\OrdersCollection;
use App\Domain\Order\Collection\OrdersWithMinExchangeVolume;
use App\Domain\Order\Order;
use App\Trading\Domain\Symbol\SymbolInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Domain\Order\Collection\OrdersWithMinExchangeVolume
 */
final class OrdersWithMinExchangeVolumeTest extends TestCase
{
    /**
     * @dataProvider testDataProvider
     */
    public function testOrders(
        SymbolInterface $symbol,
        array $sourceOrders,
        array $expectedOrders,
    ): void {
        $collection = new OrdersWithMinExchangeVolume($symbol, new OrdersCollection(...$sourceOrders));

        self::assertEquals($expectedOrders, iterator_to_array($collection));
        self::assertEquals($expectedOrders, $collection->getOrders());
        self::assertEquals(count($expectedOrders), $collection->count());
    }

    private function testDataProvider(): array
    {
        $symbol = SymbolEnum::ARCUSDT;

        return [
            [
                $symbol,
                [
                    new Order($symbol->makePrice(0.04565), 10),
                    new Order($symbol->makePrice(0.04595), 10),
                    new Order($symbol->makePrice(0.04625), 10),
                ],
                [
                    new Order($symbol->makePrice(0.04565), 110),
                    new Order($symbol->makePrice(0.04595), 109),
                    new Order($symbol->makePrice(0.04625), 109),
                ],
            ],
        ];
    }
}
