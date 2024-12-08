<?php

declare(strict_types=1);

namespace App\Bot\Application\Command;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;

final class CreateStop
{
    public function __construct(
        public readonly int $id,
        public readonly Symbol $symbol,
        public readonly Side $positionSide,
        public readonly float $volume,
        public readonly float $price,
        public readonly ?float $triggerDelta = null,
        public readonly array $context = [],
    ) {
    }
}
