<?php

declare(strict_types=1);

namespace App\Notification\Application\Service;

use App\Clock\ClockInterface;
use App\Notification\Application\Contract\AppNotificationsServiceInterface;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

final readonly class AppNotificationsService implements AppNotificationsServiceInterface
{
    private const string FROM = '07:55';
    private const string TO = '08:20';

    private LoggerInterface $logger;

    private DateTimeImmutable $from;
    private DateTimeImmutable $to;

    public function __construct(
        LoggerInterface $appNotificationLogger,
        private ClockInterface $clock
    ) {
        $this->logger = $appNotificationLogger;

        $from = explode(':', self::FROM);
        $to = explode(':', self::TO);

        $this->from = $this->clock->now()->setTime((int)$from[0], (int)$from[1]);
        $this->to = $this->clock->now()->setTime((int)$to[0], (int)$to[1]);
    }

    public function getTodaySleepPeriod(): DatePeriod
    {
        return new DatePeriod($this->from, new DateInterval('PT1M'), $this->to);
    }

    public function isNowTimeToSleep(): bool
    {
        $sleepPeriod = $this->getTodaySleepPeriod();

        $now = $this->clock->now();

        return $now >= $sleepPeriod->getStartDate() && $now <= $sleepPeriod->getEndDate();
    }

    public function notify(string $message, array $data = [], string $type = 'info'): void
    {
        if ($this->isNowTimeToSleep()) {
            return;
        }


        if (!in_array($type, ['info', 'warning', 'error'], true)) {
            throw new InvalidArgumentException(sprintf('Invalid `type` option provided (%s)', $type));
        }

        $this->logger->$type($message, $data);
    }
}
