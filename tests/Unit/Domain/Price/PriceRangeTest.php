<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Price;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Price\PriceRange;
use App\Tests\Factory\PositionFactory;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Domain\Price\PriceRange
 */
final class PriceRangeTest extends TestCase
{
    public function testCanCreate(): void
    {
        $symbol = SymbolEnum::BTCUSDT;

        $range = new PriceRange($symbol->makePrice(100.1), $symbol->makePrice(200.2), $symbol);

        self::assertEquals($symbol->makePrice(100.1), $range->from());
        self::assertEquals($symbol->makePrice(200.2), $range->to());
    }

    public function testCanWithFromToFactory(): void
    {
        $symbol = SymbolEnum::BTCUSDT;

        $range = PriceRange::create(100.1, 200.2, $symbol);

        self::assertEquals($symbol->makePrice(100.1), $range->from());
        self::assertEquals($symbol->makePrice(200.2), $range->to());
    }

    public function testFailCreate(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('$from must be greater than $to.');

        PriceRange::create(200, 200, SymbolEnum::BTCUSDT);
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
        $symbol = SymbolEnum::BTCUSDT;

        $position = PositionFactory::short($symbol, 30000, 0.5, 100);

        return [
            ['$position' => $position, '$fromPnl' => 100, '$toPnl' => -100, 'expectedRange' => PriceRange::create(29700, 30300, $symbol)],
            ['$position' => $position, '$fromPnl' => -100, '$toPnl' => 100, 'expectedRange' => PriceRange::create(29700, 30300, $symbol)],

            ['$position' => $position, '$fromPnl' => 50, '$toPnl' => -50, 'expectedRange' => PriceRange::create(29850, 30150, $symbol)],
            ['$position' => $position, '$fromPnl' => -50, '$toPnl' => 50, 'expectedRange' => PriceRange::create(29850, 30150, $symbol)],

            ['$position' => $position, '$fromPnl' => 0, '$toPnl' => 50, 'expectedRange' => PriceRange::create(29850, 30000, $symbol)],
            ['$position' => $position, '$fromPnl' => 50, '$toPnl' => 0, 'expectedRange' => PriceRange::create(29850, 30000, $symbol)],

            ['$position' => $position, '$fromPnl' => 0, '$toPnl' => -50, 'expectedRange' => PriceRange::create(30000, 30150, $symbol)],
            ['$position' => $position, '$fromPnl' => -50, '$toPnl' => 0, 'expectedRange' => PriceRange::create(30000, 30150, $symbol)],

            [
                '$position' => PositionFactory::short($symbol, 29000, 0.5, 100),
                '$fromPnl' => 0,
                '$toPnl' => -10,
                'expectedRange' => PriceRange::create(29000, 29029, $symbol)
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
        $symbol = SymbolEnum::BTCUSDT;

        return [
            [
                PriceRange::create(30000, 30300, $symbol), 30,
                [
                    $symbol->makePrice(30000),
                    $symbol->makePrice(30030),
                    $symbol->makePrice(30060),
                    $symbol->makePrice(30090),
                    $symbol->makePrice(30120),
                    $symbol->makePrice(30150),
                    $symbol->makePrice(30180),
                    $symbol->makePrice(30210),
                    $symbol->makePrice(30240),
                    $symbol->makePrice(30270)
                ], 10
            ],
            [PriceRange::create(30000, 30060, $symbol), 30, [$symbol->makePrice(30000), $symbol->makePrice(30030)], 2],
            [
                PriceRange::create(30000, 30060, $symbol), 10,
                [
                    $symbol->makePrice(30000),
                    $symbol->makePrice(30010),
                    $symbol->makePrice(30020),
                    $symbol->makePrice(30030),
                    $symbol->makePrice(30040),
                    $symbol->makePrice(30050)
                ], 6
            ],
            [
                PriceRange::create(29700, 30000, $symbol), 30,
                [
                    $symbol->makePrice(29700),
                    $symbol->makePrice(29730),
                    $symbol->makePrice(29760),
                    $symbol->makePrice(29790),
                    $symbol->makePrice(29820),
                    $symbol->makePrice(29850),
                    $symbol->makePrice(29880),
                    $symbol->makePrice(29910),
                    $symbol->makePrice(29940),
                    $symbol->makePrice(29970)
                ], 10
            ],
            [
                PriceRange::create(29940, 30000, $symbol), 30,
                [$symbol->makePrice(29940), $symbol->makePrice(29970)], 2
            ],
            [
                PriceRange::create(29940, 30000, $symbol), 10,
                [
                    $symbol->makePrice(29940),
                    $symbol->makePrice(29950),
                    $symbol->makePrice(29960),
                    $symbol->makePrice(29970),
                    $symbol->makePrice(29980),
                    $symbol->makePrice(29990)
                ], 6
            ],
            [
                PriceRange::create(29985, 30000, $symbol), 2,
                [
                    $symbol->makePrice(29985),
                    $symbol->makePrice(29987),
                    $symbol->makePrice(29989),
                    $symbol->makePrice(29991),
                    $symbol->makePrice(29993),
                    $symbol->makePrice(29995),
                    $symbol->makePrice(29997),
                    $symbol->makePrice(29999)
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
        $symbol = SymbolEnum::BTCUSDT;

        return [
            [
                PriceRange::create(30000, 30300, $symbol), 6,
                [
                    $symbol->makePrice(30000),
                    $symbol->makePrice(30050),
                    $symbol->makePrice(30100),
                    $symbol->makePrice(30150),
                    $symbol->makePrice(30200),
                    $symbol->makePrice(30250)
                ]
            ],
            [
                PriceRange::create(30000, 30300, $symbol), 10,
                [
                    $symbol->makePrice(30000),
                    $symbol->makePrice(30030),
                    $symbol->makePrice(30060),
                    $symbol->makePrice(30090),
                    $symbol->makePrice(30120),
                    $symbol->makePrice(30150),
                    $symbol->makePrice(30180),
                    $symbol->makePrice(30210),
                    $symbol->makePrice(30240),
                    $symbol->makePrice(30270)
                ]
            ],
            [
                PriceRange::create(29985, 30000, $symbol), 8,
                [
                    $symbol->makePrice(29985),
                    $symbol->makePrice(29986.875),
                    $symbol->makePrice(29988.75),
                    $symbol->makePrice(29990.625),
                    $symbol->makePrice(29992.5),
                    $symbol->makePrice(29994.375),
                    $symbol->makePrice(29996.25),
                    $symbol->makePrice(29998.125)
                ]
            ],
            [
                PriceRange::create(28710, 28971, $symbol), 10,
                [
                    $symbol->makePrice(28710),
                    $symbol->makePrice(28736.1),
                    $symbol->makePrice(28762.199999999997),
                    $symbol->makePrice(28788.299999999996),
                    $symbol->makePrice(28814.399999999994),
                    $symbol->makePrice(28840.499999999993),
                    $symbol->makePrice(28866.59999999999),
                    $symbol->makePrice(28892.69999999999),
                    $symbol->makePrice(28918.79999999999),
                    $symbol->makePrice(28944.899999999987),
                ]
            ],
        ];
    }

    public function testMiddlePrice(): void
    {
        $symbol = SymbolEnum::BTCUSDT;

        $range = PriceRange::create(37950, 38050, $symbol);

        self::assertEquals($symbol->makePrice(38000), $range->getMiddlePrice());
    }

    /**
     * @dataProvider isPriceInRangeTestCases
     */
    public function testIsPriceInRange(PriceRange $priceRange, float $price, $expectedResult): void
    {
        self::assertEquals($expectedResult, $priceRange->isPriceInRange($price));
        self::assertEquals($expectedResult, $priceRange->isPriceInRange($price));
    }

    public function isPriceInRangeTestCases(): array
    {
        $symbol = SymbolEnum::BTCUSDT;

        return [
            [PriceRange::create(100500, 200500, $symbol), 100500, true],
            [PriceRange::create(100500, 200500, $symbol), 200000, true],
            [PriceRange::create(100500, 200500, $symbol), 100499, false],
            [PriceRange::create(100500, 200500, $symbol), 200501, false],

            # todo | exception | by now using in collections (inclusivity bug)
            [PriceRange::create(100500, 200500, $symbol), 200500, false],
        ];
    }
}
