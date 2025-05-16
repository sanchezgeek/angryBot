<?php

declare(strict_types=1);

namespace App\Tests\Helper;

use App\Bot\Domain\Entity\Stop;

final class StopTestHelper
{
    public static function clone(Stop $stop): Stop
    {
        return clone $stop;
    }
}
