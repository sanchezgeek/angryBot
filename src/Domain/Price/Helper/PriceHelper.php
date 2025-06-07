<?php

declare(strict_types=1);

namespace App\Domain\Price\Helper;

use App\Domain\Price\SymbolPrice;

/**
 * @see \App\Tests\Unit\Domain\Price\Helper\PriceHelperTest
 */
final class PriceHelper
{
    private const int DEFAULT_PRECISION = 2;

    /**
     * @todo | 1st priority | All method calls (as well as FloatHelper) must be replaced with round based on traded symbol precision
     */
    public static function round(float $price, ?int $precision = null): float
    {
        $precision ??= self::DEFAULT_PRECISION;

        return \round($price, $precision);
    }

    public static function max(SymbolPrice $a, SymbolPrice $b): SymbolPrice
    {
        return $a->greaterThan($b) ? $a : $b;
    }

    public static function min(SymbolPrice $a, SymbolPrice $b): SymbolPrice
    {
        return $a->lessThan($b) ? $a : $b;
    }
}
