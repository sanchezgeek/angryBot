<?php

declare(strict_types=1);

namespace App\Messenger;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class DispatchAsyncJobHandler
{
    public function __construct(private readonly MessageBusInterface $messageBus)
    {
    }

    public function __invoke(DispatchAsync $job)
    {
        $this->messageBus->dispatch($job->message);
    }
}
