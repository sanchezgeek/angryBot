<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Order\Collection;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Order\Collection\OrdersCollection;
use App\Domain\Order\Collection\OrdersLimitedWithMaxVolume;
use App\Domain\Order\Order;
use App\Domain\Position\ValueObject\Side;
use App\Trading\Domain\Symbol\SymbolInterface;
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
        SymbolInterface $symbol,
        Side $positionSide,
        float $maxVolume,
        array $sourceOrders,
        array $expectedOrders,
    ): void {
        $collection = new OrdersLimitedWithMaxVolume(new OrdersCollection(...$sourceOrders), $maxVolume, $symbol, $positionSide);

        self::assertEquals($expectedOrders, iterator_to_array($collection));
        self::assertEquals($expectedOrders, $collection->getOrders());
        self::assertEquals(count($expectedOrders), $collection->count());
    }

    private function testDataProvider(): array
    {
        $symbol = SymbolEnum::ARCUSDT;

        return [
            // no changes
            [
                $symbol,
                Side::Buy,
                673,
                [
                    new Order($symbol->makePrice(0.02), 110),
                    new Order($symbol->makePrice(0.021), 120),
                    new Order($symbol->makePrice(0.031), 130),
                    new Order($symbol->makePrice(0.04), 140),
                    new Order($symbol->makePrice(0.05), 150),
                ],
                [
                    new Order($symbol->makePrice(0.02), 110),
                    new Order($symbol->makePrice(0.021), 120),
                    new Order($symbol->makePrice(0.031), 130),
                    new Order($symbol->makePrice(0.04), 140),
                    new Order($symbol->makePrice(0.05), 150),
                ],
            ],

            // changed

            [
                $symbol,
                Side::Buy,
                373,
                [
                    new Order($symbol->makePrice(0.02), 110),
                    new Order($symbol->makePrice(0.021), 120),
                    new Order($symbol->makePrice(0.031), 130),
                    new Order($symbol->makePrice(0.04), 140),
                    new Order($symbol->makePrice(0.05), 150),
                ],
                [
                    new Order($symbol->makePrice(0.05), 110),
                    new Order($symbol->makePrice(0.04), 120.0),
                    new Order($symbol->makePrice(0.03), 143.0),
                ],
            ],
            [
                $symbol,
                Side::Buy,
                573,
                [
                    new Order($symbol->makePrice(0.02), 110),
                    new Order($symbol->makePrice(0.021), 120),
                    new Order($symbol->makePrice(0.031), 130),
                    new Order($symbol->makePrice(0.04), 140),
                    new Order($symbol->makePrice(0.05), 150),
                ],
                [
                    new Order($symbol->makePrice(0.05), 110),
                    new Order($symbol->makePrice(0.0425), 120),
                    new Order($symbol->makePrice(0.035), 130),
                    new Order($symbol->makePrice(0.0275), 213),
                ],
            ],
            [
                $symbol,
                Side::Buy,
                678,
                [
                    new Order($symbol->makePrice(0.02), 110),
                    new Order($symbol->makePrice(0.021), 120),
                    new Order($symbol->makePrice(0.031), 130),
                    new Order($symbol->makePrice(0.04), 140),
                    new Order($symbol->makePrice(0.05), 150),
                    new Order($symbol->makePrice(0.05), 150),
                    new Order($symbol->makePrice(0.05), 150),
                ],
                [
                    new Order($symbol->makePrice(0.05), 110),
                    new Order($symbol->makePrice(0.044), 120),
                    new Order($symbol->makePrice(0.038), 130),
                    new Order($symbol->makePrice(0.032), 140),
                    new Order($symbol->makePrice(0.026), 178),
                ],
            ],
            [
                $symbol,
                Side::Buy,
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
                    new Order($symbol->makePrice(0.04835), 110),
                    new Order($symbol->makePrice(0.047), 163),
                ],
            ],
            [
                $symbol,
                Side::Sell,
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
                    new Order($symbol->makePrice(0.047), 163),
                ],
            ],
            [
                $symbol,
                Side::Sell,
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
                    new Order($symbol->makePrice(0.047), 123),
                ],
            ],
            [
                $symbol,
                Side::Sell,
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
