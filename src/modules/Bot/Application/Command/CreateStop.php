<?php

declare(strict_types=1);

namespace App\Bot\Application\Command;

use App\Domain\Position\ValueObject\Side;
use App\Trading\Domain\Symbol\SymbolInterface;

final readonly class CreateStop
{
    public function __construct(
        public int $id,
        public SymbolInterface $symbol,
        public Side $positionSide,
        public float $volume,
        public float $price,
        public ?float $triggerDelta = null,
        public array $context = [],
    ) {
    }
}
