<?php

declare(strict_types=1);

namespace App\Notification\Application\Contract;

use DatePeriod;

interface AppNotificationsServiceInterface
{
    public function getTodaySleepPeriod(): DatePeriod;
    public function isNowTimeToSleep(): bool;
    public function notify(string $message, array $data = [], string $type = 'info'): void;
}
