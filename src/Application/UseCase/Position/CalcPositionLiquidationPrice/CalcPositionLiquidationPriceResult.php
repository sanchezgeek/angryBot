<?php

declare(strict_types=1);

namespace App\Application\UseCase\Position\CalcPositionLiquidationPrice;

use App\Domain\Price\Price;

final class CalcPositionLiquidationPriceResult
{
    private Price $estimatedLiquidationPrice;

    public function __construct(Price $estimatedLiquidationPrice)
    {
        $this->estimatedLiquidationPrice = $estimatedLiquidationPrice;
    }

    public function estimatedLiquidationPrice(): Price
    {
        return $this->estimatedLiquidationPrice;
    }
}
