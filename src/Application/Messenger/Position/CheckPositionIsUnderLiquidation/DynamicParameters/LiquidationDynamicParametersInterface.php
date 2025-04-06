<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\DynamicParameters;

use App\Domain\Price\Price;
use App\Domain\Price\PriceRange;

interface LiquidationDynamicParametersInterface
{
    public function checkStopsOnDistance(): float;
    public function additionalStopTriggerDelta(): float;
    public function additionalStopPrice(): Price;
    public function warningDistance(): float;
    public function criticalDistance(): float;
    public function acceptableStoppedPart(): float;
    public function actualStopsRange(): PriceRange;
}
