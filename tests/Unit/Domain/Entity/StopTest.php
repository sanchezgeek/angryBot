<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Entity;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\PositionFactory;
use App\Tests\Mixin\PositionOrderTest;
use DomainException;
use PHPUnit\Framework\TestCase;

use function sprintf;

/**
 * @covers Stop
 */
final class StopTest extends TestCase
{
    use PositionOrderTest;

    private const WITHOUT_OPPOSITE_ORDER_CONTEXT = Stop::WITHOUT_OPPOSITE_ORDER_CONTEXT;

    public function testShortPnl(): void
    {
        $position = PositionFactory::short(Symbol::BTCUSDT, 30000, 1, 100);

        $stop = StopBuilder::short(1, 29700, 0.5)->build();
        self::assertEquals(100, $stop->getPnlInPercents($position));
        self::assertEquals(150, $stop->getPnlUsd($position));

        $stop = StopBuilder::short(1, 29600, 0.5)->build();
        self::assertEquals(133.33, $stop->getPnlInPercents($position));
        self::assertEquals(200, $stop->getPnlUsd($position));

        $stop = StopBuilder::short(1, 29000, 0.5)->build();
        self::assertEquals(333.33, $stop->getPnlInPercents($position));
        self::assertEquals(500, $stop->getPnlUsd($position));
    }

    public function testLongPnl(): void
    {
        $position = PositionFactory::long(Symbol::BTCUSDT, 30000, 1, 100);

        $stop = StopBuilder::long(1, 29700, 0.5)->build();
        self::assertEquals(-100, $stop->getPnlInPercents($position));
        self::assertEquals(-150, $stop->getPnlUsd($position));

        $stop = StopBuilder::long(1, 29600, 0.5)->build();
        self::assertEquals(-133.33, $stop->getPnlInPercents($position));
        self::assertEquals(-200, $stop->getPnlUsd($position));

        $stop = StopBuilder::long(1, 29000, 0.5)->build();
        self::assertEquals(-333.33, $stop->getPnlInPercents($position));
        self::assertEquals(-500, $stop->getPnlUsd($position));
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testGetIsWithOppositeOrder(Side $side): void
    {
        $stopWithOpposite1 = new Stop(1, 100500, 123.456, 10, $side);
        self::assertTrue($stopWithOpposite1->isWithOppositeOrder());

        $stopWithOpposite2 = new Stop(1, 100500, 123.456, 10, $side, [self::WITHOUT_OPPOSITE_ORDER_CONTEXT => false]);
        self::assertTrue($stopWithOpposite2->isWithOppositeOrder());

        $stopWithoutOpposite = new Stop(1, 100500, 123.456, 10, $side, [self::WITHOUT_OPPOSITE_ORDER_CONTEXT => true]);
        self::assertFalse($stopWithoutOpposite->isWithOppositeOrder());
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testSetIsWithOppositeOrderContext(Side $side): void
    {
        $stop = new Stop(1, 100500, 123.456, 10, $side, [self::WITHOUT_OPPOSITE_ORDER_CONTEXT => true]);
        $stop->setIsWithOppositeOrder();
        self::assertTrue($stop->isWithOppositeOrder());
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testSetIsWithoutOppositeOrderContext(Side $side): void
    {
        $stop = new Stop(1, 100500, 123.456, 10, $side);
        $stop->setIsWithoutOppositeOrder();
        self::assertFalse($stop->isWithOppositeOrder());
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testToArray(Side $positionSide): void
    {
        $stop = new Stop(
            $id = 100500,
            $price = 29000.1,
            $volume = 0.011,
            $triggerDelta = 13.1,
            $positionSide,
            $context = [
                'root.string.context' => 'some string context',
                'root.bool.context' => false,
                'some.array.context' => [
                    'inner.string.context' => 'some string context',
                    'inner.bool.context' => true,
                ],
            ],
        );

        self::assertSame(
            [
                'id' => $id,
                'positionSide' => $positionSide->value,
                'price' => $price,
                'volume' => $volume,
                'triggerDelta' => $triggerDelta,
                'context' => $context
            ],
            $stop->toArray()
        );
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testFromArray(Side $positionSide): void
    {
        $data = [
            'id' => $id = 100500,
            'positionSide' => $positionSide->value,
            'price' => $price = 29000.1,
            'volume' => $volume = 0.011,
            'triggerDelta' => $triggerDelta = 13.1,
            'context' => $context = [
                'root.string.context' => 'some string context',
                'root.bool.context' => false,
                'some.array.context' => [
                    'inner.string.context' => 'some string context',
                    'inner.bool.context' => true,
                ],
            ]
        ];

        self::assertEquals(
            new Stop($id, $price, $volume, $triggerDelta, $positionSide, $context),
            Stop::fromArray($data)
        );
    }

    public function testCanAddVolume(): void
    {
        $stop = StopBuilder::short(1, 29600, 0.5)->build();

        $stop->addVolume(0.01);

        self::assertSame(0.51, $stop->getVolume());
    }

    public function testCanSubVolume(): void
    {
        $stop = StopBuilder::short(1, 29600, 0.5)->build();

        $stop->subVolume(0.01);

        self::assertSame(0.49, $stop->getVolume());
    }

    public function testFailSubVolume(): void
    {
        $stop = StopBuilder::short(1, 29600, 0.5)->build();
        $subVolume = 0.5;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(sprintf('Cannot subtract %f from volume: the remaining volume (%f) must be >= 0.001.', $subVolume, 0));

        $stop->subVolume($subVolume);
    }

    public function testDummy(): void
    {
        self::markTestIncomplete('@todo | Should be tested later!');
    }
}
