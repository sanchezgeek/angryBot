<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\BuyOrder;

use App\Domain\BuyOrder\BuyOrdersCollection;
use App\Tests\Factory\Entity\BuyOrderBuilder;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

final class BuyOrdersCollectionTest extends TestCase
{
    public function testHas(): void
    {
        $collection = new BuyOrdersCollection();

        $collection->add(BuyOrderBuilder::short(100500, 29000, 0.001)->build());

        self::assertFalse($collection->has(1));
        self::assertTrue($collection->has(100500));
        self::assertFalse($collection->has(999999));
    }

    public function testItems(): void
    {
        $collection = new BuyOrdersCollection();

        $collection->add(BuyOrderBuilder::short(1, 29000, 0.001)->build());
        $collection->add(BuyOrderBuilder::short(3, 29100, 0.02)->build());
        $collection->add(BuyOrderBuilder::short(2, 29050, 0.01)->build());

        self::assertEquals([
            BuyOrderBuilder::short(1, 29000, 0.001)->build(),
            BuyOrderBuilder::short(3, 29100, 0.02)->build(),
            BuyOrderBuilder::short(2, 29050, 0.01)->build(),
        ], iterator_to_array($collection));

        self::assertEquals(3, $collection->totalCount());
        self::assertEquals(0.031, $collection->totalVolume());
    }

    public function testFailAddAgain(): void
    {
        $collection = new BuyOrdersCollection();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('BuyOrder with id "100500" was added before.');

        $collection->add(BuyOrderBuilder::short(100500, 29000, 0.001)->build());
        $collection->add(BuyOrderBuilder::short(100500, 29000, 0.001)->build());
    }

    public function testDummy(): void
    {
        self::markTestIncomplete('Test BuyOrdersCollection!');
    }
}
