<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\DynamicParameters;

use App\Domain\Price\SymbolPrice;
use App\Domain\Price\PriceRange;
use App\Domain\Value\Percent\Percent;

interface LiquidationDynamicParametersInterface
{
    public function checkStopsOnDistance(): float;
    public function additionalStopTriggerDelta(): float;
    public function additionalStopPrice(): SymbolPrice;

    public function transferFromSpotOnDistance(): float;

    public function warningDistance(): float;
    public function warningRange(): PriceRange;
    public function criticalDistance(): float;
    public function criticalRange(): PriceRange;
    public function acceptableStoppedPart(): float;
    public function actualStopsRange(): PriceRange;

    public function addOppositeBuyOrdersAfterStop(): bool;
    public function warningDistancePnlPercent(): float;

    public function percentOfLiquidationDistanceToAddStop(): Percent;
}
