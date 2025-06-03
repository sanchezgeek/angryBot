<?php

declare(strict_types=1);

namespace App\Bot\Domain\Repository;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Position\ValueObject\Side;

interface StopRepositoryInterface
{
    /**
     * @return Stop[]
     */
    public function findActive(SymbolInterface $symbol, Side $side, ?Ticker $nearTicker = null, bool $exceptOppositeOrders = false, ?callable $qbModifier = null): array;
}
