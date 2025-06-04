<?php

declare(strict_types=1);

namespace App\Bot\Application\Command;

use App\Domain\Position\ValueObject\Side;
use App\Trading\Domain\Symbol\SymbolInterface;

final class CreateBuyOrder
{
    public function __construct(
        public readonly int $id,
        public readonly SymbolInterface $symbol,
        public readonly Side $positionSide,
        public readonly float $volume,
        public readonly float $price,
        public readonly float $triggerDelta,
        public readonly array $context = [],
    ) {
    }
}
