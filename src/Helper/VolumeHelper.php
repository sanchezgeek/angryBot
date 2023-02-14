<?php

declare(strict_types=1);

namespace App\Helper;

final class VolumeHelper
{
    public const MIN_VOLUME = 0.001;
    private const DEFAULT_PRECISION = 3;

    public static function round(float $volume, int $precision = self::DEFAULT_PRECISION): float
    {
        $rounded = \round($volume, $precision);

        if ($rounded < self::MIN_VOLUME) {
            return self::MIN_VOLUME;
        }

        return $rounded;
    }
}
