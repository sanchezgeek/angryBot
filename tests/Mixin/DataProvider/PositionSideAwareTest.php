<?php

declare(strict_types=1);

namespace App\Tests\Mixin\DataProvider;

use App\Domain\Position\ValueObject\Side;

trait PositionSideAwareTest
{
    /**
     * @return Side[]
     */
    private function positionSideProvider(): array
    {
        return [[Side::Sell], [Side::Buy]];
    }
}
