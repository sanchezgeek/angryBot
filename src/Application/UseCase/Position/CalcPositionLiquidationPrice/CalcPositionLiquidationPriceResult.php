<?php

declare(strict_types=1);

namespace App\Application\UseCase\Position\CalcPositionLiquidationPrice;

use App\Domain\Price\Price;

final readonly class CalcPositionLiquidationPriceResult
{
    public function __construct(
        private Price $positionEntryPrice,
        private Price $estimatedLiquidationPrice,
    ) {
    }

    public function estimatedLiquidationPrice(): Price
    {
        return $this->estimatedLiquidationPrice;
    }

    public function liquidationDistance(): float
    {
        return $this->positionEntryPrice->deltaWith($this->estimatedLiquidationPrice);
    }

    public function positionEntryPrice(): Price
    {
        return $this->positionEntryPrice;
    }
}
