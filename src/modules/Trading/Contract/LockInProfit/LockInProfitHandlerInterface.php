<?php

declare(strict_types=1);

namespace App\Trading\Contract\LockInProfit;

interface LockInProfitHandlerInterface
{
    public function handle(LockInProfitEntry $entry);
}
