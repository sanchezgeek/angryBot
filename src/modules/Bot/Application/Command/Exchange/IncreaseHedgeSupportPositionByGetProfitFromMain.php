<?php

declare(strict_types=1);

namespace App\Bot\Application\Command\Exchange;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Position\ValueObject\Side;

readonly final class IncreaseHedgeSupportPositionByGetProfitFromMain
{
    public function __construct(
        public SymbolInterface $symbol,
        public Side $side,
        public float $qty
    ) {
    }
}
