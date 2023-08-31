<?php

declare(strict_types=1);

namespace App\Domain\Price\Helper;

/**
 * @see \App\Tests\Unit\Domain\Price\Helper\PriceHelperTest
 */
final class PriceHelper
{
    private const DEFAULT_PRECISION = 2;

    public static function round(float $price, int $precision = self::DEFAULT_PRECISION): float
    {
        return \round($price, $precision);
    }
}
