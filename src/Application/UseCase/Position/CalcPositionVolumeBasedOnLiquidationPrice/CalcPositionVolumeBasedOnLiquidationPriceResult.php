<?php

declare(strict_types=1);

namespace App\Application\UseCase\Position\CalcPositionVolumeBasedOnLiquidationPrice;

use App\Domain\Price\SymbolPrice;

final readonly class CalcPositionVolumeBasedOnLiquidationPriceResult
{
    public function __construct(
        public float $resultVolume,
        public float $diff,
        public ?SymbolPrice $realLiquidationPrice = null,
    ) {
    }
}
