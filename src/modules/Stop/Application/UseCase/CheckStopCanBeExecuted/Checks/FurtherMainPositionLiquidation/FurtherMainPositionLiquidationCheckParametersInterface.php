<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\CheckStopCanBeExecuted\Checks\FurtherMainPositionLiquidation;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Price\Price;

interface FurtherMainPositionLiquidationCheckParametersInterface
{
    public function mainPositionSafeLiquidationPriceDelta(Symbol $symbol, Price $tickerPrice): float;
}
