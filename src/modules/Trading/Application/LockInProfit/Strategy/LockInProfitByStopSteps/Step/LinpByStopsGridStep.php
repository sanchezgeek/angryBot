<?php

declare(strict_types=1);

namespace App\Trading\Application\LockInProfit\Strategy\LockInProfitByStopSteps\Step;

use App\Domain\Trading\Enum\PriceDistanceSelector;

final class LinpByStopsGridStep
{
    public function __construct(
        public string $stepAlias,
        public PriceDistanceSelector $checkOnPriceLength,
        public string $gridsDefinition
    ) {
    }
}
