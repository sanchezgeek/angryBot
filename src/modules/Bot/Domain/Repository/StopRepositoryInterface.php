<?php

declare(strict_types=1);

namespace App\Bot\Domain\Repository;

use App\Bot\Domain\Entity\Stop;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\PriceRange;
use App\Trading\Domain\Symbol\SymbolInterface;

interface StopRepositoryInterface
{
    public function save(Stop $stop);
    public function remove(Stop $stop);

    /**
     * @return Stop[]
     */
    public function findActive(?SymbolInterface $symbol = null, ?Side $side = null, bool $exceptOppositeOrders = false, ?callable $qbModifier = null): array;

    public function findActiveInRange(SymbolInterface $symbol, Side $side, PriceRange $priceRange, bool $exceptOppositeOrders = false, ?callable $qbModifier = null): array;

    /**
     * @return Stop[]
     */
    public function findStopsWithFakeExchangeOrderId(): array;

    public function getByLockInProfitStepAlias(SymbolInterface $symbol, Side $positionSide, string $stepAlias): array;
    public function getCreatedAsLockInProfit(?SymbolInterface $symbol = null, ?Side $positionSide = null): array;
}
