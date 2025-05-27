<?php

declare(strict_types=1);

namespace App\Tests\Assertions;

use App\Bot\Domain\Position;
use PHPUnit\Framework\Constraint\IsEqual;

final class PositionAssertions
{
    /**
     * @param Position[] $expected
     * @param Position[] $actual
     */
    public static function assertPositionsEquals(array $expected, array $actual): void
    {
        foreach ($expected as $position) {
            $position->initializeHedge();
            $position->oppositePosition?->initializeHedge();
        }
        foreach ($actual as $position) {
            $position->initializeHedge();
            $position->oppositePosition?->initializeHedge();
        }

        $constraint = new IsEqual($expected);
        $constraint->evaluate($actual);
    }
}
