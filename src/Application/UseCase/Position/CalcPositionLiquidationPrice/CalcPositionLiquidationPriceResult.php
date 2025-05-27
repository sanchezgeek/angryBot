<?php

declare(strict_types=1);

namespace App\Application\UseCase\Position\CalcPositionLiquidationPrice;

use App\Domain\Price\SymbolPrice;

final readonly class CalcPositionLiquidationPriceResult
{
    public function __construct(
        private SymbolPrice $positionEntryPrice,
        private SymbolPrice $estimatedLiquidationPrice,
    ) {
    }

    public function estimatedLiquidationPrice(): SymbolPrice
    {
        return $this->estimatedLiquidationPrice;
    }

    public function liquidationDistance(): float
    {
        return $this->positionEntryPrice->deltaWith($this->estimatedLiquidationPrice);
    }

    public function positionEntryPrice(): SymbolPrice
    {
        return $this->positionEntryPrice;
    }
}
