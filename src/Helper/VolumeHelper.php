<?php

declare(strict_types=1);

namespace App\Helper;

final class VolumeHelper
{
    public const MIN_VOLUME = 0.001;
    private const PRECISION = 3;

    public static function round(float $volume): float
    {
        if (
            ($rounded = \round($volume, self::PRECISION)) < self::MIN_VOLUME
        ) {
            return self::MIN_VOLUME;
        }

        return $rounded;
    }
}
