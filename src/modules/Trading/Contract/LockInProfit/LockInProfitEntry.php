<?php

declare(strict_types=1);

namespace App\Trading\Contract\LockInProfit;

use App\Bot\Domain\Position;
use App\Domain\Price\SymbolPrice;

final class LockInProfitEntry
{
    public function __construct(
        public Position $position,
        public SymbolPrice $currentMarkPrice,
        public object $innerStrategyDto
    ) {
    }
}
