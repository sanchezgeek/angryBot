<?php

declare(strict_types=1);

namespace App\Infrastructure\EventListener\Symfony\Messenger;

use App\Application\Messenger\TimeStampedAsyncMessageTrait;
use App\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

#[AsEventListener]
final readonly class WorkerMessageReceivedListener
{
    public function __construct(
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(WorkerMessageReceivedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();

        if (in_array(TimeStampedAsyncMessageTrait::class, class_uses($message), true)) {
            /** @var TimeStampedAsyncMessageTrait $message */

            $messageClass = explode('\\', get_class($message));
            $messageClass = end($messageClass);

            $dispatchedAt = $message->getDispatchedDateTime();
            $receivedAt = $this->clock->now();
            var_dump($delta = ((float)$receivedAt->format('U.u')) - ((float)$dispatchedAt->format('U.u')));

            $this->logger->debug(sprintf('%s created at: %s', $messageClass, $dispatchedAt->format('H:i:s.u')));
            $this->logger->debug(sprintf('%s handled at: %s', $messageClass, $receivedAt->format('H:i:s.u')));
            $this->logger->debug(sprintf('% ' . strlen($messageClass . ' handled at') . 's: %s', 'delta', $delta));
        }
    }
}
