<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Stop;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Stop\PositionStopRangesCollection;
use App\Domain\Stop\StopsCollection;
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\PositionFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Domain\Stop\PositionStopRangesCollection
 */
final class PositionStopRangesCollectionTest extends TestCase
{
    /**
     * @dataProvider rangesTestDataProvider
     */
    public function testCanCreate(Position $position, array $stops, array $expectedStopsRanges): void
    {
        $positionStopsRanges = new PositionStopRangesCollection($position, new StopsCollection(...$stops), 30);

        $stopsRanges = [];
        foreach ($positionStopsRanges as $range => $stopsInRange) {
            $stopsRanges[$range] = $stopsInRange;
        }

        self::assertEquals($expectedStopsRanges, $stopsRanges);
    }

    private function rangesTestDataProvider(): iterable
    {
        yield [
            PositionFactory::short(Symbol::BTCUSDT, 30000, 1, 100),
            [
                StopBuilder::short(10, 29549, 0.001)->build(),
                StopBuilder::short(20, 29551, 0.002)->build(),
                StopBuilder::short(30, 29570, 0.002)->build(),
                StopBuilder::short(40, 29650, 0.003)->build(),
                StopBuilder::short(50, 29680, 0.004)->build(),
                StopBuilder::short(60, 29710, 0.005)->build(),
                StopBuilder::short(70, 29790, 0.006)->build(),
                StopBuilder::short(80, 29850, 0.007)->build(),
                StopBuilder::short(90, 29890, 0.008)->build(),
                StopBuilder::short(100, 29890, 0.009)->build(),
                StopBuilder::short(110, 29912, 0.01)->build(),
            ],
            [
                '   0% ..   30%' => new StopsCollection(
                    StopBuilder::short(110, 29912, 0.01)->build(),
                ),
                '  30% ..   60%' => new StopsCollection(
                    StopBuilder::short(80, 29850, 0.007)->build(),
                    StopBuilder::short(90, 29890, 0.008)->build(),
                    StopBuilder::short(100, 29890, 0.009)->build(),
                ),
                '  60% ..   90%' => new StopsCollection(
                    StopBuilder::short(70, 29790, 0.006)->build(),
                ),
                '  90% ..  120%' => new StopsCollection(
                    StopBuilder::short(40, 29650, 0.003)->build(),
                    StopBuilder::short(50, 29680, 0.004)->build(),
                    StopBuilder::short(60, 29710, 0.005)->build(),
                ),
                ' 120% ..  150%' => new StopsCollection(
                    StopBuilder::short(20, 29551, 0.002)->build(),
                    StopBuilder::short(30, 29570, 0.002)->build(),
                ),
                ' 150% ..  180%' => new StopsCollection(
                    StopBuilder::short(10, 29549, 0.001)->build(),
                ),
            ]
        ];
    }
}
