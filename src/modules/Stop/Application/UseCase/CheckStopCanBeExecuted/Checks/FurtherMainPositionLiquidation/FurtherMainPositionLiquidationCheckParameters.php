<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\CheckStopCanBeExecuted\Checks\FurtherMainPositionLiquidation;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Price\Price;

final class FurtherMainPositionLiquidationCheckParameters implements FurtherMainPositionLiquidationCheckParametersInterface
{
    public function mainPositionSafeLiquidationPriceDelta(Symbol $symbol, Price $tickerPrice): float
    {
//        $this->saveLiquidationDistanceForMainPositionAfterCloseSupport = $this->settings->get(PushStopSettings::MainPositionSafeLiqDistance_After_PushSupportPositionStops);
        $tickerPrice = $tickerPrice->value();

        return match (true) {
            default => match (true) {
                $tickerPrice > 10000 => $tickerPrice / 10,
                $tickerPrice > 3000 => $tickerPrice / 8,
                $tickerPrice > 2000 => $tickerPrice / 7,
                $tickerPrice > 1000 => $tickerPrice / 5,
                $tickerPrice > 100 => $tickerPrice / 4,
                $tickerPrice > 1 => $tickerPrice / 6,
                $tickerPrice > 0.1 => $tickerPrice / 7,
                $tickerPrice > 0.05 => $tickerPrice / 3,
                default => $tickerPrice * 2,
                // default => $closingPosition->entryPrice()->deltaWith($ticker->markPrice) * 2
            }
        };
    }
}
