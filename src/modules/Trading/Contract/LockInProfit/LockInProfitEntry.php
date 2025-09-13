<?php

declare(strict_types=1);

namespace App\Trading\Contract\LockInProfit;

use App\Bot\Domain\Position;

final class LockInProfitEntry
{
    public function __construct(
        public Position $position,
        public object $innerStrategyDto
    ) {
    }
}
