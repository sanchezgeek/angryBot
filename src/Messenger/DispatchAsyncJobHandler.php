<?php

declare(strict_types=1);

namespace App\Messenger;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class DispatchAsyncJobHandler
{
    public function __construct(private MessageBusInterface $messageBus)
    {
    }

    public function __invoke(DispatchAsync $job): void
    {
        $this->messageBus->dispatch($job->message);
    }
}
