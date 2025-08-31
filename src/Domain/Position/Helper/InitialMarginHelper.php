<?php

declare(strict_types=1);

namespace App\Domain\Position\Helper;

use App\Bot\Domain\Position;

final class InitialMarginHelper
{
    public static function realInitialMargin(Position $position): float
    {
        $k = $position->leverage->value() / 100;

        return $position->initialMargin->value() * $k;
    }
}
