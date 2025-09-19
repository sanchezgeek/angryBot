<?php

declare(strict_types=1);

namespace App\Trading\Contract;

use App\Bot\Domain\Position;
use App\Domain\Value\Percent\Percent;
use App\Trading\Domain\Symbol\SymbolInterface;

interface PositionInfoProviderInterface
{
    public function getRealInitialMarginToTotalContractBalanceRatio(SymbolInterface $symbol, Position $position): Percent;
}
