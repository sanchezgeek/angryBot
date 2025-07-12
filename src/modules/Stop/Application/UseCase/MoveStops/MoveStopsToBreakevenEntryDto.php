<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\MoveStops;

use App\Bot\Domain\Position;

final class MoveStopsToBreakevenEntryDto
{
    public function __construct(
        public Position $position,
        public float $positionPnlPercent,
        public bool $excludeFixations
    ) {
    }

    public static function simple(
        Position $position,
        float $positionPnlPercent
    ): self {
        return new self($position, $positionPnlPercent, false);
    }

    public static function excludeFixationStops(
        Position $position,
        float $positionPnlPercent
    ): self
    {
        return new self($position, $positionPnlPercent, true);
    }
}
