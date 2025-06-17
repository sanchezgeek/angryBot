<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Domain\SimpleCalculations;

final class CurrentATRCalculation
{
    public static function calc(
        float $prevTRsSum,
        float $TRonCurrentCandle,
        int $period
    ): float {
        $atr0 = $prevTRsSum / $period;

        return ($atr0 * ($period - 1) + $TRonCurrentCandle) / $period;
    }
}
