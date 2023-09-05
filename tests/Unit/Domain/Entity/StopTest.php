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

    public function testSub(): void
    {
        self::markTestIncomplete('@todo | Should be tested later!');
    }

    public function testDummy(): void
    {
        self::markTestIncomplete('@todo | Should be tested later!');
    }
}
