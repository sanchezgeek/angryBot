<?php

declare(strict_types=1);

namespace App\Service\Infrastructure\Job\Ping;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PingMessagesHandler
{
    public function __invoke(PingMessages $message): void
    {
        $this->logger->warning('ping');
    }

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }
}
