<?php

declare(strict_types=1);

namespace App\Messenger\SchedulerTransport;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use Exception;

final class PeriodicalJob implements JobScheduleInterface
{
    public function __construct(private DatePeriod $period, private object $job)
    {
    }

    /**
     * @throws Exception if invalid date or period
     */
    public static function infinite(string $start, $interval, object $job): self
    {
        $interval = $interval instanceof DateInterval ? $interval : new DateInterval($interval);

        return new self(new DatePeriod(new DateTimeImmutable($start), $interval, 9999999999999), $job);
    }

    public function getNextRun(DateTimeImmutable $lastTick): ?DateTimeImmutable
    {
        $startDate = $this->period->getStartDate();
        $endDate = $this->period->getEndDate();
        $gridStep = 0;

        if ($startDate > $lastTick) {
            return DateTimeImmutable::createFromFormat('U.u', $startDate->format('U.u')) ?: null;
        }

        if ($endDate && $endDate < $lastTick) {
            return null;
        }

        foreach ($this->period as $firstRunDate) {
            $gridStep = (float)$firstRunDate->format('U.u') - (float)$startDate->format('U.u');
            if ($gridStep > 0) {
                break;
            }
        }

        if ($gridStep === 0) {
            // @codeCoverageIgnoreStart
            return null;
            // @codeCoverageIgnoreEnd
        }

        $delta = (float)$lastTick->format('U.u') - (float)$startDate->format('U.u');
        $recurrencesPassed = (int)($delta / $gridStep);

        $maxRecurrences = $this->period->getRecurrences();
        if ($maxRecurrences) {
            /**
             * @psalm-suppress UndefinedPropertyFetch
             */
            if ($this->period->include_start_date) {
                --$maxRecurrences;
            }
            if ($recurrencesPassed >= $maxRecurrences) {
                return null;
            }
        }

        $nextRunTimestamp = \number_format(($recurrencesPassed + 1) * $gridStep + (float)$startDate->format('U.u'), 6, thousands_separator: '');

        return DateTimeImmutable::createFromFormat('U.u', (string)$nextRunTimestamp) ?: null;
    }

    public function getJob(): object
    {
        return $this->job;
    }
}
