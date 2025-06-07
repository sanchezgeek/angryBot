<?php

declare(strict_types=1);

namespace App\Bot\Domain\Repository;

use App\Bot\Domain\Ticker;
use App\Domain\Position\ValueObject\Side;
use App\Trading\Domain\Symbol\SymbolInterface;

interface PositionOrderRepository
{
    public function findActive(
        SymbolInterface $symbol,
        Side $side,
        ?Ticker $nearTicker = null,
        bool $exceptOppositeOrders = false,
        ?callable $qbModifier = null
    ): array;
}
