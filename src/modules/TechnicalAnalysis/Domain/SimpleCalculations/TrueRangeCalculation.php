<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Domain\SimpleCalculations;

use App\Domain\Value\Percent\Percent;
use App\TechnicalAnalysis\Domain\Dto\CandleDto;
use App\TechnicalAnalysis\Domain\Dto\TAPriceChange;

final class TrueRangeCalculation
{
    public static function calc(
        CandleDto $currentCandle,
        ?CandleDto $prevCandle = null,
    ): TAPriceChange {
        $options = [
            abs($currentCandle->high - $currentCandle->low),
        ];

        if ($prevCandle) {
            $options[] = abs($currentCandle->high - $prevCandle->close);
            $options[] = abs($prevCandle->close - $currentCandle->low);
        }

        $max = max($options);

        $key = array_key_first(array_intersect($options, [$max]));

        $refPrice = match($key) {
            0 => $currentCandle->low,
            1, 2 => $prevCandle->close,
        };

        $percent = Percent::fromPart($max / $refPrice);

        return new TAPriceChange(
            $currentCandle->interval,
            $percent,
            $max,
            $refPrice
        );
    }
}
