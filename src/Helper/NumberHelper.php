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
final class NumberHelper
{
    public static function minMax(float $value, float $min, float $max): float
    {
        return min(max($value, $min), $max);
    }
}
