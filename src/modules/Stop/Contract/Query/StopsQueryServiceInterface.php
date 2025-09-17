<?php

declare(strict_types=1);

namespace App\Stop\Contract\Query;

use App\Bot\Domain\Position;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;

interface StopsQueryServiceInterface
{
    public function getAnyKindOfFixationsCountBeforePrice(Side $positionSide, SymbolPrice $price, SymbolPrice $tickerPrice): int;
}
