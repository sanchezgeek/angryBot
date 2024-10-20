<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\Async;

use App\Clock\ClockInterface;
use App\Infrastructure\Symfony\Messenger\Async\Debug\MessageWithDispatchingTimeTrait;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

use function class_uses;
use function in_array;

/**
 * Handler dispatch messages created with scheduler asynchronously
 */
#[AsMessageHandler]
final readonly class DispatchAsyncMessageHandler
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private ClockInterface $clock
    ) {
    }

    public function __invoke(AsyncMessage $job): void
    {
        $message = $job->message;

        if (in_array(MessageWithDispatchingTimeTrait::class, class_uses($message), true)) {
            /** @var MessageWithDispatchingTimeTrait $message */
            $message->setDispatchedDateTime($this->clock->now());
        }

        $this->messageBus->dispatch($message);
    }
}
