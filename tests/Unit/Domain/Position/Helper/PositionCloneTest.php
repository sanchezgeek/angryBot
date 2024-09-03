<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Position\Helper;

use App\Bot\Domain\Position;
use App\Domain\Position\Helper\PositionClone;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Mixin\DataProvider\PositionSideAwareTest;
use PHPUnit\Framework\TestCase;

class PositionCloneTest extends TestCase
{
    use PositionSideAwareTest;

    /**
     * @dataProvider positionSideProvider
     */
    public function testCloneWithNewSize(Side $side): void
    {
        $liquidationDistance = 1500;
        $position = PositionBuilder::bySide($side)->entry(50000)->size(0.1)->liqDistance($liquidationDistance)->build();

        // Act
        $result = PositionClone::full($position)->withSize(0.11)->create();

        // Assert
        $expectedPosition = new Position($position->side, $position->symbol, $position->entryPrice, 0.11, 5500, $side->isLong() ? $position->entryPrice - $liquidationDistance : $position->entryPrice + $liquidationDistance, 55, 55, 100);
        self::assertEquals($expectedPosition, $result);
    }

    public function testDummy(): void
    {
        self::markTestSkipped('test different cases');
    }
}
