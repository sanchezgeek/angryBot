<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Order;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Order;
use App\Domain\Order\OrdersGrid;
use App\Domain\Price\Price;
use App\Domain\Price\PriceRange;
use App\Tests\Factory\PositionFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Domain\Order\OrdersGrid
 */
final class OrdersGridTest extends TestCase
{
    /**
     * @dataProvider createCases
     */
    public function testCreateByPositionPnlRange(
        Position $p,
        int $fromPnl,
        int $toPnl,
        PriceRange $expRange,
    ): void {
        $priceGrid = OrdersGrid::byPositionPnlRange($p, $fromPnl, $toPnl);

        self::assertEquals($expRange, $priceGrid->getPriceRange());
    }

    private function createCases(): array
    {
        $position = PositionFactory::short(Symbol::BTCUSDT, 30000, 0.5, 100);

        return [
            ['position' => $position, 'fromPnl' => -25, 'toPnl' => 0, 'expectedRange' => PriceRange::create(30000, 30075)],
            ['position' => $position, 'fromPnl' => 0, 'toPnl' => -25, 'expectedRange' => PriceRange::create(30000, 30075)],

            ['position' => $position, 'fromPnl' => 5, 'toPnl' => 0, 'expectedRange' => PriceRange::create(29985, 30000)],
            ['position' => $position, 'fromPnl' => 0, 'toPnl' => 5, 'expectedRange' => PriceRange::create(29985, 30000)],

            [
                'position' => PositionFactory::short(Symbol::BTCUSDT, 29000, 0.5, 100),
                'fromPnl' => 0,
                'toPnl' => -5,
                'expectedRange' => PriceRange::create(29000, 29014.5),
            ], [
                'position' => PositionFactory::short(Symbol::BTCUSDT, 29000, 0.5, 100),
                'fromPnl' => -5,
                'toPnl' => 0,
                'expectedRange' => PriceRange::create(29000, 29014.5),
            ],

            [
                'position' => PositionFactory::short(Symbol::BTCUSDT, 29000, 0.5, 100),
                'fromPnl' => 5,
                'toPnl' => -5,
                'expectedRange' => PriceRange::create(28985.5, 29014.5),
            ], [
                'position' => PositionFactory::short(Symbol::BTCUSDT, 29000, 0.5, 100),
                'fromPnl' => -5,
                'toPnl' => 5,
                'expectedRange' => PriceRange::create(28985.5, 29014.5),
            ],
        ];
    }

    /**
     * @dataProvider getOrdersByPriceStepDataProvider
     */
    public function testGetOrdersByPriceStep(
        Position $position,
        int $fromPnl,
        int $toPnl,
        int $priceStep,
        float $forVolume,
        array $expectedOrders
    ): void {
        // @todo create by constructor
        $priceGrid = OrdersGrid::byPositionPnlRange($position, $fromPnl, $toPnl);

        // Assert
        $orders = [];
        foreach ($priceGrid->ordersByPriceStep($forVolume, $priceStep) as $order) {
            $orders[] = $order;
        }

        self::assertEquals($expectedOrders, $orders);
    }

    private function getOrdersByPriceStepDataProvider(): array
    {
        $position = PositionFactory::short(Symbol::BTCUSDT, 30000, 0.5, 100);

        return [
            [
                'position' => $position,
                'fromPnl' => 0,
                'toPnl' => -25,
                'priceStep' => 20,
                'forVolume' => 0.05,
                'expectedOrders' => [
                    new Order(Price::float(30000), 0.013),
                    new Order(Price::float(30020), 0.013),
                    new Order(Price::float(30040), 0.013),
                    new Order(Price::float(30060), 0.013),
                ]
            ],
            [
                'position' => $position,
                'fromPnl' => 0,
                'toPnl' => 5,
                'priceStep' => 2,
                'forVolume' => 0.05,
                'expectedOrders' => [
                    new Order(Price::float(29985), 0.006),
                    new Order(Price::float(29987), 0.006),
                    new Order(Price::float(29989), 0.006),
                    new Order(Price::float(29991), 0.006),
                    new Order(Price::float(29993), 0.006),
                    new Order(Price::float(29995), 0.006),
                    new Order(Price::float(29997), 0.006),
                    new Order(Price::float(29999), 0.006),
                ]
            ],
            [
                'position' => PositionFactory::short(Symbol::BTCUSDT, 29000, 0.5, 100),
                'fromPnl' => 0,
                'toPnl' => -5,
                'priceStep' => 2,
                'forVolume' => 0.03,
                'expectedOrders' => [
                    new Order(Price::float(29000), 0.004),
                    new Order(Price::float(29002), 0.004),
                    new Order(Price::float(29004), 0.004),
                    new Order(Price::float(29006), 0.004),
                    new Order(Price::float(29008), 0.004),
                    new Order(Price::float(29010), 0.004),
                    new Order(Price::float(29012), 0.004),
                    new Order(Price::float(29014), 0.004),
                ]
            ],
        ];
    }

    /**
     * @dataProvider getOrdersByQntDataProvider
     */
    public function testGetOrdersByQnt(PriceRange $priceRange, float $forVolume, int $qnt, array $expectedOrders): void
    {
        $priceGrid = new OrdersGrid($priceRange);

        // Assert
        $orders = [];
        foreach ($priceGrid->ordersByQnt($forVolume, $qnt) as $order) {
            $orders[] = $order;
        }

        self::assertEquals($expectedOrders, $orders);
    }

    private function getOrdersByQntDataProvider(): array
    {
        return [
            [
                '$priceRange' => PriceRange::create(30000, 30075),
                '$forVolume' => 0.05,
                '$qnt' => 4,
                'expectedOrders' => [
                    new Order(Price::float(30000), 0.013),
                    new Order(Price::float(30018.75), 0.013),
                    new Order(Price::float(30037.5), 0.013),
                    new Order(Price::float(30056.25), 0.013),
                ]
            ],
            [
                '$priceRange' => PriceRange::create(29985, 30000),
                '$forVolume' => 0.05,
                '$qnt' => 4,
                'expectedOrders' => [
                    new Order(Price::float(29985), 0.013),
                    new Order(Price::float(29988.75), 0.013),
                    new Order(Price::float(29992.5), 0.013),
                    new Order(Price::float(29996.25), 0.013),
                ]
            ],
            [
                '$priceRange' => PriceRange::create(29985, 30000),
                '$forVolume' => 0.05,
                '$qnt' => 8,
                'expectedOrders' => [
                    new Order(Price::float(29985), 0.006),
                    new Order(Price::float(29986.875), 0.006),
                    new Order(Price::float(29988.75), 0.006),
                    new Order(Price::float(29990.625), 0.006),
                    new Order(Price::float(29992.5), 0.006),
                    new Order(Price::float(29994.375), 0.006),
                    new Order(Price::float(29996.25), 0.006),
                    new Order(Price::float(29998.125), 0.006),
                ]
            ],
            [
                '$priceRange' => PriceRange::create(29000, 29014.5),
                '$forVolume' => 0.05,
                '$qnt' => 6,
                'expectedOrders' => [
                    new Order(Price::float(29000), 0.008),
                    new Order(Price::float(29002.416666666668), 0.008),
                    new Order(Price::float(29004.833333333336), 0.008),
                    new Order(Price::float(29007.250000000004), 0.008),
                    new Order(Price::float(29009.66666666667), 0.008),
                    new Order(Price::float(29012.08333333334), 0.008),
                ]
            ],
            [
                '$priceRange' => PriceRange::byPositionPnlRange(
                    PositionFactory::short(Symbol::BTCUSDT, 29000, 1.5), 10, 100
                ),
                '$forVolume' => 0.03,
                '$qnt' => 10,
                'expectedOrders' => [
                    new Order(Price::float(28710), 0.003),
                    new Order(Price::float(28736.1), 0.003),
                    new Order(Price::float(28762.199999999997), 0.003),
                    new Order(Price::float(28788.299999999996), 0.003),
                    new Order(Price::float(28814.399999999994), 0.003),
                    new Order(Price::float(28840.499999999993), 0.003),
                    new Order(Price::float(28866.59999999999), 0.003),
                    new Order(Price::float(28892.69999999999), 0.003),
                    new Order(Price::float(28918.79999999999), 0.003),
                    new Order(Price::float(28944.899999999987), 0.003),

                ]
            ],
            [
                '$priceRange' => PriceRange::create(28710, 28971),
                '$forVolume' => 0.005,
                '$qnt' => 10,
                'expectedOrders' => [
                    new Order(Price::float(28710), 0.001),
                    new Order(Price::float(28762.2), 0.001),
                    new Order(Price::float(28814.4), 0.001),
                    new Order(Price::float(28866.600000000002), 0.001),
                    new Order(Price::float(28918.800000000003), 0.001),

                ]
            ],
        ];
    }
}
