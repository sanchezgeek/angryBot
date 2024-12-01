<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Assert;

use App\Bot\Domain\Position;

trait PositionsAssertions
{
    public static function isPositionsEqual(Position $a, Position $b): void
    {
        $a->uninitializeRuntimeCache();
        $b->uninitializeRuntimeCache();

        self::assertEquals($a, $b);
    }
}
