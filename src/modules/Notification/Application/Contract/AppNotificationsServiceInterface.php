<?php

declare(strict_types=1);

namespace App\Notification\Application\Contract;

use App\Notification\Application\Contract\Enum\SoundLength;
use DatePeriod;

interface AppNotificationsServiceInterface
{
    public function getTodaySleepPeriod(): DatePeriod;
    public function isNowTimeToSleep(): bool;
    public function muted(string $message, array $data = []): void;
    public function notify(string $message, array $data = [], string $type = 'info', SoundLength $length = SoundLength::DEFAULT): void;
    public function info(string $message, array $data = [], SoundLength $length = SoundLength::DEFAULT): void;
    public function warning(string $message, array $data = [], SoundLength $length = SoundLength::DEFAULT): void;
    public function error(string $message, array $data = [], SoundLength $length = SoundLength::DEFAULT): void;
}
