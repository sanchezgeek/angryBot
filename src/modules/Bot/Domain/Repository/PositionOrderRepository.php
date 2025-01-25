<?php

declare(strict_types=1);

namespace App\Bot\Domain\Repository;

use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;

interface PositionOrderRepository
{
    public function findActive(
        Symbol $symbol,
        Side $side,
        ?Ticker $nearTicker = null,
        bool $exceptOppositeOrders = false,
        callable $qbModifier = null
    ): array;
}
