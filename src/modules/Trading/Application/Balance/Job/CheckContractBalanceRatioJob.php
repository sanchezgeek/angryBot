<?php

declare(strict_types=1);

namespace App\Trading\Application\Balance\Job;

use App\Domain\Coin\Coin;

final class CheckContractBalanceRatioJob
{
    public function __construct(public Coin $coin)
    {
    }
}
