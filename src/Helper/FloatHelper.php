<?php

declare(strict_types=1);

namespace App\Helper;

use function random_int;

final class FloatHelper
{
    private const DEFAULT_ROUND_PRECISION = 3;

    public static function round(float $value, int $precision = self::DEFAULT_ROUND_PRECISION): float
    {
        return \round($value, $precision);
    }

    /**
     * @todo | test
     */
    public static function modify(int|float $number, float $subPercentPart, float $addPercentPart = null): int|float
    {
        if ($_ENV['APP_ENV'] === 'test') {
            return $number;
        }

        if ($addPercentPart === null) {
            $addPercentPart = $subPercentPart;
        }

        $upModifier = 1000;

        $subDiff = (int) ($number * $subPercentPart) * $upModifier;
        $addDiff = (int) ($number * $addPercentPart) * $upModifier;
        $randValue = random_int(-$subDiff, $addDiff) / $upModifier;

        return self::round($number + $randValue, 3);
    }
}
