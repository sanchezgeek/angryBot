<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\CheckStopCanBeExecuted\Checks\FurtherMainPositionLiquidation;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Price;

interface FurtherMainPositionLiquidationCheckParametersInterface
{
    public function mainPositionSafeLiquidationPriceDelta(Symbol $symbol, Side $side, float $refPrice): float;
}
