<?php

declare(strict_types=1);

namespace App\Bot\Application\Events;

interface LoggingEvent
{
    public function getLog(): string;
}
