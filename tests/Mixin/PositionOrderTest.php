<?php

declare(strict_types=1);

namespace App\Tests\Mixin;

use App\Domain\Position\ValueObject\Side;

trait PositionOrderTest
{
    /**
     * @return Side[]
     */
    private function positionSideProvider(): array
    {
        return [[Side::Sell], [Side::Buy]];
    }
}
