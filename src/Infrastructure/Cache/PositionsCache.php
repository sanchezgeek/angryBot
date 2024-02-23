<?php

namespace App\Infrastructure\Cache;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;

interface PositionsCache
{
    public function clearPositionCache(Symbol $symbol, Side $positionSide): void;
}
