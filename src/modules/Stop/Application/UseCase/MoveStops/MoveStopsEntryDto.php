<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\MoveStops;

use App\Bot\Domain\Position;

final class MoveStopsEntryDto
{
    public function __construct(
        public Position $position,
        public float $positionPnlPercent
    ) {
    }
}
