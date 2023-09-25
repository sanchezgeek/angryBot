<?php

declare(strict_types=1);

namespace App\Bot\Application\Events;

abstract class LoggableEvent
{
    abstract public function getLog(): ?string;

    public function getContext(): array
    {
        return [];
    }
}
