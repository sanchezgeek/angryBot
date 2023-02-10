<?php

declare(strict_types=1);

namespace App\Bot\Domain;

use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Domain\ValueObject\Symbol;

final class Position
{
    public function __construct(
        public readonly Side $side,
        public readonly Symbol $symbol,
        public readonly float $entryPrice,
        public readonly float $size,
        public readonly float $liquidationPrice,
    ) {
    }

    public function getCaption(): string
    {
        $type = $this->side === Side::Sell ? 'SHORT' : 'LONG';

        return $this->symbol->value . ' ' . $type;
    }
}
