<?php

namespace App\EventBus;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class EventBus
{
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher
    ) {
    }

    public function handleEvent(Event $event): void
    {
        $this->eventDispatcher->dispatch($event, get_class($event));
    }

    public function handleEvents(HasEvents $eventsHolder): void
    {
        foreach ($eventsHolder->releaseEvents() as $event) {
            $this->handleEvent($event);
        }
    }
}
