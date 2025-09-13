<?php

declare(strict_types=1);

namespace App\Trading\Application\LockInProfit\Strategy\LockInProfitByPeriodicalFixations\Step;

use App\Domain\Trading\Enum\PriceDistanceSelector;
use App\Domain\Value\Percent\Percent;

final class PeriodicalFixationStep
{
    public function __construct(
        public string $alias,
        public PriceDistanceSelector $applyAfterPriceLength,
        public Percent $singleFixationPart,
        public Percent $maxPositionSizePart,
        public int $secondsInterval,
        public bool $mustPreviousBeApplied = false, // @todo make true with e.g. cautious RiskLevel (for more profit locked)
    ) {
    }
}
