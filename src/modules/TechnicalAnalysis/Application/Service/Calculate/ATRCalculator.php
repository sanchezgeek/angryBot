<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Service\Calculate;

use App\Helper\OutputHelper;
use App\TechnicalAnalysis\Domain\Dto\CandleDto;
use App\TechnicalAnalysis\Domain\Dto\PriceChange;
use App\TechnicalAnalysis\Domain\SimpleCalculations\CurrentATRCalculation;
use App\TechnicalAnalysis\Domain\SimpleCalculations\TrueRangeCalculation;
use RuntimeException;

final class ATRCalculator
{
    /**
     * @param CandleDto[] $candles
     */
    public static function calculate(int $period, array $candles)
    {
        $totalCandlesCount = count($candles);
        assert($period === $totalCandlesCount - 1, new RuntimeException(
            sprintf('%s: something went wrong ($period !== $totalCandlesCount - 1)', OutputHelper::shortClassName(__CLASS__))
        ));

        $lastKey = $totalCandlesCount - 1;
        $initialTRs = [];

        $trN = null;
        foreach ($candles as $key => $candle) {
            $tr = TrueRangeCalculation::calc($candle, $candles[$key - 1] ?? null);

            if ($key !== $lastKey) {
                $initialTRs[] = $tr;
            } else {
                $trN = $tr;
            }
        }

        assert(count($initialTRs) === $period, new RuntimeException(
            sprintf('%s: something went wrong (count($initialTRs) !== $period)', OutputHelper::shortClassName(__CLASS__))
        ));

        $initialTRsSum = array_sum(array_map(static fn(PriceChange $priceChange) => $priceChange->absoluteChange, $initialTRs));

        return CurrentATRCalculation::calc($initialTRsSum, $trN->absoluteChange, $period);
    }
}
