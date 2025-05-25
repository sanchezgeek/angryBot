<?php

declare(strict_types=1);

namespace App\Application\Notification;

interface AppNotificationLoggerInterface
{
    public function notify(string $message, array $data = []): void;
}
