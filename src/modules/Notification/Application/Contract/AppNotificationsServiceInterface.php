<?php

declare(strict_types=1);

namespace App\Notification\Application\Contract;

use DatePeriod;

interface AppNotificationsServiceInterface
{
    public function getTodaySleepPeriod(): DatePeriod;
    public function isNowTimeToSleep(): bool;
}
