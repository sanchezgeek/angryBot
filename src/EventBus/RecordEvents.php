<?php

namespace App\EventBus;

trait RecordEvents
{
    private array $events = [];

    public function releaseEvents(): array
    {
        $events = $this->events;

        $this->events = [];

        return $events;
    }

    protected function recordThat(Event $event): void
    {
        $this->events[] = $event;
    }
}
