<?php

declare(strict_types=1);

namespace App\Screener\Application\Job;

use App\Domain\Coin\Coin;
use App\Trading\Application\AutoOpen\Decision\Criteria\AbstractOpenPositionCriteria;
use LogicException;

final class CheckSignificantPriceChangeJob
{
//    /**
//     * @param AbstractOpenPositionCriteria[] $criteriasSuggestions
//     */
    public function __construct(
        public Coin $settleCoin,
        public int $daysDelta,
        public bool $autoOpen = false,
        public ?float $atrBaseMultiplierOverride = null,
//        public array $criteriasSuggestions = []
    ) {
        if ($this->daysDelta < 0) {
            throw new LogicException(sprintf('Days delta cannot be less than 0 (%s provided)', $this->daysDelta));
        }
    }
}
