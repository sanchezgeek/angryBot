<?php

declare(strict_types=1);

namespace App\Bot\Application\Command\Exchange;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;

readonly final class IncreaseHedgeSupportPositionByGetProfitFromMain
{
    public function __construct(
        public Symbol $symbol,
        public Side $side,
        public float $qty
    ) {
    }
}
