<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Application\Messenger\TimeStampedAsyncMessageTrait;
use App\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

use function class_uses;
use function in_array;

#[AsMessageHandler]
final readonly class DispatchAsyncJobHandler
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private ClockInterface $clock
    ) {
    }

    public function __invoke(Async $job): void
    {
        $message = $job->message;

        if (in_array(TimeStampedAsyncMessageTrait::class, class_uses($message), true)) {
            /** @var TimeStampedAsyncMessageTrait $message */
            $message->setDispatchedDateTime($this->clock->now());
        }

        $this->messageBus->dispatch($message);
    }
}
