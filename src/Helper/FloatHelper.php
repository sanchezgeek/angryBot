<?php

declare(strict_types=1);

namespace App\Helper;

final class FloatHelper
{
    private const DEFAULT_PRECISION = 3;

    public static function round(float $value, int $precision = self::DEFAULT_PRECISION): float
    {
        return \round($value, $precision);
    }
}
