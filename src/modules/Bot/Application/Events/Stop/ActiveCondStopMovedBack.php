<?php

declare(strict_types=1);

namespace App\Bot\Application\Events\Stop;

use App\Bot\Application\Events\LoggableEvent;
use App\Bot\Domain\Entity\Stop;

final class ActiveCondStopMovedBack extends LoggableEvent
{
    public function __construct(public Stop $stop)
    {
    }

    public function getLog(): string
    {
        return \sprintf(
            'Conditional stop (%s) moved back (volume = %.2f, price = %.2f, delta=%.1f)',
            $this->stop->getPositionSide()->value,
            $this->stop->getVolume(),
            $this->stop->getPrice(),
            $this->stop->getTriggerDelta()
        );
    }
}
