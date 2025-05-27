<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Application\Notification\AppNotificationLoggerInterface;
use Psr\Log\LoggerInterface;

readonly class SymfonyAppNotificationLogger implements AppNotificationLoggerInterface
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $appNotificationLogger)
    {
        $this->logger = $appNotificationLogger;
    }

    public function notify(string $message, array $data = []): void
    {
        $this->logger->info($message, $data);
    }
}
