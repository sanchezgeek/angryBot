<?php

declare(strict_types=1);

namespace App\Bot\Domain;

use App\Bot\Domain\ValueObject\Position\Side;

interface PositionOrderRepository
{
    public function findActive(Side $side, ?Ticker $ticker = null, callable $qbModifier = null): array;
}
