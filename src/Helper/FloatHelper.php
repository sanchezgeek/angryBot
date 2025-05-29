<?php

declare(strict_types=1);

namespace App\Helper;

use App\Worker\AppContext;

use function abs;
use function count;
use function explode;
use function is_float;
use function number_format;
use function pow;
use function random_int;
use function round;
use function rtrim;
use function strlen;

/**
 * @see \App\Tests\Unit\Helper\FloatHelperTest
 */
final class FloatHelper
{
    public const DEFAULT_ROUND_PRECISION = 3;

    public static function round(float $value, ?int $precision = null): float
    {
        $precision = $precision ?? self::DEFAULT_ROUND_PRECISION;

        $min = \round(pow(0.1, $precision), $precision);

        $rounded = \round($value, $precision);
        if ($value !== 0.00 && abs($rounded) < $min) {
            return $value >= 0 ? $min : -$min;
        }

        return $rounded;
    }

    /**
     * @todo | test
     */
    public static function modify(int|float $number, float $subPercentPart, ?float $addPercentPart = null, bool $force = false): int|float
    {
        if (!$force && AppContext::isTest()) {
            return $number;
        }

        if ($addPercentPart === null) {
            $addPercentPart = $subPercentPart;
        }

        if (is_float($number)) {
            $str = explode('.', rtrim(number_format($number, 10), '0'));
            if (count($str) < 2) {
                $precision = 3;
                $upModifier = \round(pow(10, $precision), $precision);
            } else {
                $precision = strlen($str[1]) + 1;
                $upModifier = round(pow(10, $precision));
            }
        } else {
            $upModifier = 1000;
        }

        $subDiff = (int) (($number * $subPercentPart) * $upModifier);
        $addDiff = (int) (($number * $addPercentPart) * $upModifier);
        $randValue = random_int(-$subDiff, $addDiff) / $upModifier;

        return $number + $randValue;
    }
}
