<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Price;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Price\Price;
use App\Domain\Price\PriceRange;
use App\Tests\Factory\PositionFactory;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * @covers PriceRange
 */
final class PriceRangeTest extends TestCase
{
    public function testCanCreate(): void
    {
        $range = new PriceRange(Price::float(100.1), Price::float(200.2));

        self::assertEquals(Price::float(100.1), $range->from());
        self::assertEquals(Price::float(200.2), $range->to());
    }

    public function testCanWithFromToFactory(): void
    {
        $range = PriceRange::create(100.1, 200.2);

        self::assertEquals(Price::float(100.1), $range->from());
        self::assertEquals(Price::float(200.2), $range->to());
    }

    public function testFailCreate(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('$from must be greater than $to.');

        PriceRange::create(200, 200);
    }

    /**
     * @dataProvider byPositionPnlRangeProvider
     */
    public function testCanCreateByPositionPnlRange(
        Position $position,
        int $fromPnl,
        int $toPnl,
        PriceRange $expectedRange
    ): void {
        $range = PriceRange::byPositionPnlRange($position, $fromPnl, $toPnl);

        self::assertEquals($expectedRange, $range);
    }

    private function byPositionPnlRangeProvider(): array
    {
        $position = PositionFactory::short(Symbol::BTCUSDT, 30000, 0.5, 100);

        return [
            ['$position' => $position, '$fromPnl' => 100, '$toPnl' => -100, 'expectedRange' => PriceRange::create(29700, 30300)],
            ['$position' => $position, '$fromPnl' => -100, '$toPnl' => 100, 'expectedRange' => PriceRange::create(29700, 30300)],

            ['$position' => $position, '$fromPnl' => 50, '$toPnl' => -50, 'expectedRange' => PriceRange::create(29850, 30150)],
            ['$position' => $position, '$fromPnl' => -50, '$toPnl' => 50, 'expectedRange' => PriceRange::create(29850, 30150)],

            ['$position' => $position, '$fromPnl' => 0, '$toPnl' => 50, 'expectedRange' => PriceRange::create(29850, 30000)],
            ['$position' => $position, '$fromPnl' => 50, '$toPnl' => 0, 'expectedRange' => PriceRange::create(29850, 30000)],

            ['$position' => $position, '$fromPnl' => 0, '$toPnl' => -50, 'expectedRange' => PriceRange::create(30000, 30150)],
            ['$position' => $position, '$fromPnl' => -50, '$toPnl' => 0, 'expectedRange' => PriceRange::create(30000, 30150)],

            [
                '$position' => PositionFactory::short(Symbol::BTCUSDT, 29000, 0.5, 100),
                '$fromPnl' => 0,
                '$toPnl' => -10,
                'expectedRange' => PriceRange::create(29000, 29029)
            ],
        ];
    }

    /**
     * @dataProvider itemsByStepDataProvider
     */
    public function testCanGetItemsByStep(PriceRange $range, int $priceStep, array $expItems, int $expQnt): void
    {
        $items = [];
        foreach ($range->byStepIterator($priceStep) as $priceItem) {
            $items[] = $priceItem;
        }

        self::assertEquals($expItems, $items);
        self::assertEquals($expQnt, $range->getItemsQntByStep($priceStep));
    }

    private function itemsByStepDataProvider(): array
    {
        return [
            [
                PriceRange::create(30000, 30300), 30,
                [
                    Price::float(30000),
                    Price::float(30030),
                    Price::float(30060),
                    Price::float(30090),
                    Price::float(30120),
                    Price::float(30150),
                    Price::float(30180),
                    Price::float(30210),
                    Price::float(30240),
                    Price::float(30270)
                ], 10
            ],
            [PriceRange::create(30000, 30060), 30, [Price::float(30000), Price::float(30030)], 2],
            [
                PriceRange::create(30000, 30060), 10,
                [
                    Price::float(30000),
                    Price::float(30010),
                    Price::float(30020),
                    Price::float(30030),
                    Price::float(30040),
                    Price::float(30050)
                ], 6
            ],
            [
                PriceRange::create(29700, 30000), 30,
                [
                    Price::float(29700),
                    Price::float(29730),
                    Price::float(29760),
                    Price::float(29790),
                    Price::float(29820),
                    Price::float(29850),
                    Price::float(29880),
                    Price::float(29910),
                    Price::float(29940),
                    Price::float(29970)
                ], 10
            ],
            [
                PriceRange::create(29940, 30000), 30,
                [Price::float(29940), Price::float(29970)], 2
            ],
            [
                PriceRange::create(29940, 30000), 10,
                [
                    Price::float(29940),
                    Price::float(29950),
                    Price::float(29960),
                    Price::float(29970),
                    Price::float(29980),
                    Price::float(29990)
                ], 6
            ],
            [
                PriceRange::create(29985, 30000), 2,
                [
                    Price::float(29985),
                    Price::float(29987),
                    Price::float(29989),
                    Price::float(29991),
                    Price::float(29993),
                    Price::float(29995),
                    Price::float(29997),
                    Price::float(29999)
                ], 8
            ],
        ];
    }

    /**
     * @dataProvider itemsByQntDataProvider
     */
    public function testCanGetItemsByQnt(PriceRange $range, int $qnt, array $expItems): void
    {
        $items = [];
        foreach ($range->byQntIterator($qnt) as $priceItem) {
            $items[] = $priceItem;
        }

        self::assertEquals($expItems, $items);
    }

    private function itemsByQntDataProvider(): array
    {
        return [
            [
                PriceRange::create(30000, 30300), 6,
                [
                    Price::float(30000),
                    Price::float(30050),
                    Price::float(30100),
                    Price::float(30150),
                    Price::float(30200),
                    Price::float(30250)
                ]
            ],
            [
                PriceRange::create(30000, 30300), 10,
                [
                    Price::float(30000),
                    Price::float(30030),
                    Price::float(30060),
                    Price::float(30090),
                    Price::float(30120),
                    Price::float(30150),
                    Price::float(30180),
                    Price::float(30210),
                    Price::float(30240),
                    Price::float(30270)
                ]
            ],
            [
                PriceRange::create(29985, 30000), 8,
                [
                    Price::float(29985),
                    Price::float(29986.88),
                    Price::float(29988.75),
                    Price::float(29990.63),
                    Price::float(29992.5),
                    Price::float(29994.38),
                    Price::float(29996.25),
                    Price::float(29998.13)
                ]
            ],
            [
                PriceRange::create(28710, 28971), 10,
                [
                    Price::float(28710),
                    Price::float(28736.1),
                    Price::float(28762.2),
                    Price::float(28788.3),
                    Price::float(28814.4),
                    Price::float(28840.5),
                    Price::float(28866.6),
                    Price::float(28892.7),
                    Price::float(28918.8),
                    Price::float(28944.9),
                ]
            ],
        ];
    }
}
