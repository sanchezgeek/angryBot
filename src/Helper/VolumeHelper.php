<?php

declare(strict_types=1);

namespace App\Helper;

/**
 * @todo | for now only for BTCUSDT?
 */
final class VolumeHelper
{
    public const MIN_VOLUME = 0.001;
    private const DEFAULT_PRECISION = 3;

    /**
     * @todo | must not be static | must based on trading symbol
     */
    public static function round(float $volume, int $precision = self::DEFAULT_PRECISION): float
    {
        $rounded = \round($volume, $precision);

        if ($rounded < self::MIN_VOLUME) {
            return self::MIN_VOLUME;
        }

        return $rounded;
    }

    public static function forceRoundUp(float $volume, int $precision = self::DEFAULT_PRECISION): float
    {
        $fig = 10 ** $precision;
        $rounded = (ceil($volume * $fig) / $fig);

        if ($rounded < self::MIN_VOLUME) {
            return self::MIN_VOLUME;
        }

        return $rounded;
    }
}
