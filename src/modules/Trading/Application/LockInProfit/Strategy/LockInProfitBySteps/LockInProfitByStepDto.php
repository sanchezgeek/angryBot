<?php

declare(strict_types=1);

namespace App\Trading\Application\LockInProfit\Strategy\LockInProfitBySteps;

use App\Domain\Trading\Enum\PriceDistanceSelector;

final class LockInProfitByStepDto
{
    public function __construct(
        public string $alias,
        public PriceDistanceSelector $checkOnPriceLength,
        public string $gridsDefinition
    ) {
    }
}
