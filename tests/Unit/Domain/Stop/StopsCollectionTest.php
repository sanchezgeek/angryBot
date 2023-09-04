<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Stop;

use App\Domain\Price\PriceRange;
use App\Domain\Stop\StopsCollection;
use App\Tests\Factory\Entity\StopBuilder;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

/**
 * @covers StopsCollection
 */
final class StopsCollectionTest extends TestCase
{
    public function testHas(): void
    {
        $collection = new StopsCollection();

        $collection->add(StopBuilder::short(100500, 29000, 0.001)->build());

        self::assertFalse($collection->has(1));
        self::assertTrue($collection->has(100500));
        self::assertFalse($collection->has(999999));
    }

    public function testItems(): void
    {
        $collection = new StopsCollection();

        $collection->add(StopBuilder::short(1, 29000, 0.001)->build());
        $collection->add(StopBuilder::short(3, 29100, 0.02)->build());
        $collection->add(StopBuilder::short(2, 29050, 0.01)->build());

        self::assertEquals([
            StopBuilder::short(1, 29000, 0.001)->build(),
            StopBuilder::short(3, 29100, 0.02)->build(),
            StopBuilder::short(2, 29050, 0.01)->build(),
        ], iterator_to_array($collection));

        self::assertEquals(3, $collection->totalCount());
        self::assertEquals(0.031, $collection->totalVolume());
        self::assertEquals(29100.0, $collection->getMaxPrice());
        self::assertEquals(29000.0, $collection->getMinPrice());
    }

    public function testGrabFromRange(): void
    {
        $collection = new StopsCollection();

        $collection->add(StopBuilder::short(1, 29000, 0.001)->build());
        $collection->add(StopBuilder::short(3, 29010, 0.001)->build());
        $collection->add(StopBuilder::short(2, 29020, 0.02)->build());
        $collection->add(StopBuilder::short(4, 29050, 0.01)->build());

        $range = PriceRange::create(29005, 29021);

        self::assertEquals(
            new StopsCollection(
                StopBuilder::short(3, 29010, 0.001)->build(),
                StopBuilder::short(2, 29020, 0.02)->build(),
            ),
            $collection->grabFromRange($range)
        );
    }

    public function testFailAddAgain(): void
    {
        $collection = new StopsCollection();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Stop with id "100500" was added before.');

        $collection->add(StopBuilder::short(100500, 29000, 0.001)->build());
        $collection->add(StopBuilder::short(100500, 29000, 0.001)->build());
    }
}
