<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Entity;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\PositionFactory;
use App\Tests\Mixin\PositionOrderTest;
use PHPUnit\Framework\TestCase;

/**
 * @covers Stop
 */
final class StopTest extends TestCase
{
    use PositionOrderTest;

    private const WITHOUT_OPPOSITE_ORDER_CONTEXT = Stop::WITHOUT_OPPOSITE_ORDER_CONTEXT;

    public function testPnl(): void
    {
        $position = PositionFactory::short(Symbol::BTCUSDT, 30000, 1, 100);

        $stop = StopBuilder::short(1, 29700, 0.5)->build();

        self::assertEquals(100, $stop->getPnlInPercents($position));
        self::assertEquals(150, $stop->getPnlUsd($position));
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

    public function testSub(): void
    {
        self::markTestIncomplete('@todo | Should be tested later!');
    }

    public function testDummy(): void
    {
        self::markTestIncomplete('@todo | Should be tested later!');
    }
}
