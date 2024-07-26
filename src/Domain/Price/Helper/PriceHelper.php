<?php

declare(strict_types=1);

namespace App\Domain\Price\Helper;

use App\Domain\Price\Price;

/**
 * @see \App\Tests\Unit\Domain\Price\Helper\PriceHelperTest
 */
final class PriceHelper
{
    private const DEFAULT_PRECISION = 2;

    /**
     * @todo | 1st priority | All method calls (as well as FloatHelper) must be replaced with round based on traded symbol precision
     */
    public static function round(float $price, int $precision = self::DEFAULT_PRECISION): float
    {
        return \round($price, $precision);
    }

    public static function max(Price $a, Price $b): Price
    {
        return $a->greaterThan($b) ? $a : $b;
    }

    public static function min(Price $a, Price $b): Price
    {
        return $a->lessThan($b) ? $a : $b;
    }
}
