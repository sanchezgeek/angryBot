<?php

declare(strict_types=1);

namespace App\Liquidation\Domain\Assert\Result;

final class PositionLiquidationIsSafeAssertionResult
{
    public function __construct(
        public bool $success,
        public float $usedPrice,
    ) {
    }
}
