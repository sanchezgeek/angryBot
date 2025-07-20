<?php

declare(strict_types=1);

namespace App\Domain\Stop\Event;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\EventBus\Event;

final class StopPushedToExchange implements Event
{
    public function __construct(
        public Stop $stop,
        public Position $prevPositionState,
    ) {
    }
}
