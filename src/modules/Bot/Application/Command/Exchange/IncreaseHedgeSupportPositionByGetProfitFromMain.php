<?php

declare(strict_types=1);

namespace App\Bot\Application\Command\Exchange;

use App\Domain\Position\ValueObject\Side;
use App\Trading\Domain\Symbol\SymbolInterface;

readonly final class IncreaseHedgeSupportPositionByGetProfitFromMain
{
    public function __construct(
        public SymbolInterface $symbol,
        public Side $side,
        public float $qty
    ) {
    }
}
