<?php

declare(strict_types=1);

namespace App\Trading\Contract;

use App\Bot\Domain\Position;
use App\Domain\Value\Percent\Percent;

interface PositionInfoProviderInterface
{
    public function getRealInitialMarginToTotalContractBalanceRatio(Position $position): Percent;
}
