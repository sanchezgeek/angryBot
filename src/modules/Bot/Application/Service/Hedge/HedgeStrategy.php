<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Hedge;

use App\Buy\Application\StopPlacementStrategy;

final readonly class HedgeStrategy
{
    public function __construct(
        public StopPlacementStrategy $supportPositionStopCreation,
        public StopPlacementStrategy $mainPositionStopCreation,
        public ?string $description = null
    ) {
    }
}
