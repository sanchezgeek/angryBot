<?php

declare(strict_types=1);

namespace App\Trading\Application\LockInProfit\Strategy;

use App\Trading\Contract\LockInProfit\LockInProfitEntry;

interface LockInpProfitStrategyInterface
{
    public function supports(LockInProfitEntry $entry): bool;
    public function process(LockInProfitEntry $entry): void;
}
