<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Hedge;

use App\Bot\Domain\Strategy\StopCreate;

final readonly class HedgeStrategy
{
    public function __construct(
        public StopCreate $supportPositionStopCreation,
        public StopCreate $mainPositionStopCreation,
        public ?string $description = null
    ) {
    }
}
