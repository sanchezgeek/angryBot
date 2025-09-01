<?php

declare(strict_types=1);

namespace App\Trading\Contract\LockInProfit;

use App\Bot\Domain\Position;
use App\Trading\Contract\LockInProfit\Enum\LockInProfitStrategy;

final class LockInProfitEntry
{
    public function __construct(
        public Position $position,
        public LockInProfitStrategy $strategy,
        public object $innerStrategyDto
    ) {
    }
}
