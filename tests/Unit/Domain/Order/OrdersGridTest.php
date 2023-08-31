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
 * @covers OrdersGrid
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
                    new Order(Price::float(29986.88), 0.006),
                    new Order(Price::float(29988.75), 0.006),
                    new Order(Price::float(29990.63), 0.006),
                    new Order(Price::float(29992.5), 0.006),
                    new Order(Price::float(29994.38), 0.006),
                    new Order(Price::float(29996.25), 0.006),
                    new Order(Price::float(29998.13), 0.006),
                ]
            ],
            [
                '$priceRange' => PriceRange::create(29000, 29014.5),
                '$forVolume' => 0.05,
                '$qnt' => 6,
                'expectedOrders' => [
                    new Order(Price::float(29000), 0.008),
                    new Order(Price::float(29002.42), 0.008),
                    new Order(Price::float(29004.83), 0.008),
                    new Order(Price::float(29007.25), 0.008),
                    new Order(Price::float(29009.67), 0.008),
                    new Order(Price::float(29012.08), 0.008),
                ]
            ],
        ];
    }
}
