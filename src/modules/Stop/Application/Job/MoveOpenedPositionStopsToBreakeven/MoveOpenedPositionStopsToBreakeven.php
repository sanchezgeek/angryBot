<?php

declare(strict_types=1);

namespace App\Stop\Application\Job\MoveOpenedPositionStopsToBreakeven;

final class MoveOpenedPositionStopsToBreakeven
{
    public function __construct(
        public float $targetPositionPnlPercent,
        public bool $excludeFixationsStop,
        public ?float $pnlGreaterThan = null,
    ) {
    }
}
