<?php

declare(strict_types=1);

namespace App\Screener\Application\Job;

use App\Domain\Coin\Coin;
use LogicException;

final class CheckSignificantPriceChangeJob
{
    public function __construct(
        public Coin $settleCoin,
        public int $daysDelta,
        public bool $autoOpen = false
    ) {
        if ($this->daysDelta < 0) {
            throw new LogicException(sprintf('Days delta cannot be less than 0 (%s provided)', $this->daysDelta));
        }
    }
}
