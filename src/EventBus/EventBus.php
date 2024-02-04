<?php

namespace App\EventBus;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_shift;

final class EventBus
{
    private array $events = [];

    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function dispatch(Event $event): void
    {
        $this->eventDispatcher->dispatch($event, \get_class($event));
    }

    public function put(Event $event): void
    {
        $this->events[] = $event;
    }

    public function dispatchCollectedEvents(): void
    {
        while (count($this->events)) {
            $event = array_shift($this->events);
            $this->dispatch($event);
        }

        $this->events = [];
    }

    public function handleEvents(HasEvents $eventsHolder): void
    {
        foreach ($eventsHolder->releaseEvents() as $event) {
            $this->dispatch($event);
        }
    }
}
