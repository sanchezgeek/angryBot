<?php

declare(strict_types=1);

namespace App\Messenger\SchedulerTransport;

use Exception;

final readonly class PeriodicalJob implements JobScheduleInterface
{
    public function __construct(private \DatePeriod $period, private object $job)
    {
    }

    /**
     * @throws Exception if invalid date or period
     */
    public static function infinite($start, $interval, object $job): self
    {
        $interval = $interval instanceof \DateInterval ? $interval : new \DateInterval($interval);
        $start = $start instanceof \DateTimeImmutable ? $start : new \DateTimeImmutable($start);

        return new self(new \DatePeriod($start, $interval, 9999999999999), $job);
    }

    public function getNextRun(\DateTimeImmutable $lastTick): ?\DateTimeImmutable
    {
        $startDate = $this->period->getStartDate();
        $endDate = $this->period->getEndDate();
        $gridStep = 0;

        if ($startDate > $lastTick) {
            return \DateTimeImmutable::createFromFormat('U.u', $startDate->format('U.u')) ?: null;
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

        $nextRun = ($recurrencesPassed + 1) * $gridStep + (float)$startDate->format('U.u');

        return \DateTimeImmutable::createFromFormat('U.u', \number_format($nextRun, 6, thousands_separator: '')) ?: null;
    }

    public function getJob(): object
    {
        return $this->job;
    }
}
