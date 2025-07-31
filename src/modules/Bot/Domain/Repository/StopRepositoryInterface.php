<?php

declare(strict_types=1);

namespace App\Bot\Domain\Repository;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Ticker;
use App\Domain\Position\ValueObject\Side;
use App\Trading\Domain\Symbol\SymbolInterface;

interface StopRepositoryInterface
{
    public function save(Stop $stop);
    public function remove(Stop $stop);

    /**
     * @return Stop[]
     */
    public function findActive(?SymbolInterface $symbol = null, ?Side $side = null, ?Ticker $nearTicker = null, bool $exceptOppositeOrders = false, ?callable $qbModifier = null): array;

    /**
     * @return Stop[]
     */
    public function findStopsWithFakeExchangeOrderId(): array;
}
