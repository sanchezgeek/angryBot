<?php

declare(strict_types=1);

namespace App\Stop\Contract\Query;

use App\Bot\Domain\Position;
use App\Domain\Price\SymbolPrice;

interface StopsQueryServiceInterface
{
    public function getAnyKindOfFixationsCountBeforePositionEntry(Position $position, SymbolPrice $tickerPrice): int;
}
