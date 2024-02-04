<?php

declare(strict_types=1);

namespace App\Infrastructure\EventListener\DomainEvents;

use App\EventBus\EventBus;
use App\EventBus\HasEvents;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Proxy\Proxy;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

final class TriggerDomainEventsListener implements EventSubscriberInterface
{
    private EventBus $eventBus;

    public function __construct(EventBus $eventBus)
    {
        $this->eventBus = $eventBus;
    }

    public function postFlush(PostFlushEventArgs $eventArgs): void
    {
        // @todo $this->initializeRollbackListenerIfNeeded($eventArgs);

        $identityMap = $eventArgs->getEntityManager()->getUnitOfWork()->getIdentityMap();

        foreach ($identityMap as $className => $entities) {
            foreach ($entities as $entity) {
                if ($entity instanceof Proxy && !$entity->__isInitialized()) {
                    continue;
                }

                if (!$entity instanceof HasEvents) {
                    continue;
                }

                foreach ($entity->releaseEvents() as $event) {
                    $this->eventBus->put($event);
                }
            }
        }
    }

    public function onWorkerMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $this->eventBus->dispatchCollectedEvents();
    }

    public function onWorkerMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $this->eventBus->dispatchCollectedEvents();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageHandledEvent::class => 'onWorkerMessageHandled',
            WorkerMessageFailedEvent::class => 'onWorkerMessageFailed',
        ];
    }
}
