<?php

declare(strict_types=1);

namespace App\Bot\Application\Events\Stop;

use App\Bot\Application\Events\LoggableEvent;
use App\Bot\Domain\Entity\Stop;

final class StopPushedToExchange extends LoggableEvent
{
    public function __construct(Stop $stop)
    {
    }

    public function getLog(): string
    {
        return 'stop log';
    }
}
