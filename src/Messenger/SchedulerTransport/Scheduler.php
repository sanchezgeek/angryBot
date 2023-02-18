<?php

declare(strict_types=1);

namespace App\Messenger\SchedulerTransport;

use App\Clock\ClockInterface;
use DateTimeImmutable;
use Generator;
use IteratorAggregate;

/**
 * @implements IteratorAggregate<JobScheduleInterface>
 */
final class Scheduler implements IteratorAggregate
{
    private DateTimeImmutable $lastTick;
    private DateTimeImmutable $nextRun;

    /**
     * @var JobScheduleInterface[]
     */
    private array $jobSchedules;

    /**
     * @param JobScheduleInterface[] $jobSchedules
     */
    public function __construct(
        private readonly ClockInterface $clock,
        iterable $jobSchedules
    ) {
        $this->jobSchedules = (static fn (JobScheduleInterface ...$a) => $a)(...$jobSchedules);

        $currentTime = $this->clock->now();

        $this->lastTick = $currentTime;
        $this->nextRun = $currentTime;
    }

    /**
     * @return Generator<JobScheduleInterface>
     */
    public function getIterator(): Generator
    {
        return $this->getJobs();
    }

    public function getJobs(): Generator
    {
        $currentTime = $this->clock->now();
        $lastTick = $this->lastTick;
        $this->lastTick = $currentTime;

        if ($currentTime < $this->nextRun) {
            return;
        }

        $nearestNextRun = new DateTimeImmutable('3000-01-01');
        foreach ($this->jobSchedules as $jobSchedule) {
            $nextRun = $jobSchedule->getNextRun($lastTick);
            if ($nextRun === null) {
                continue;
            }
            if ($nearestNextRun > $nextRun) {
                $nearestNextRun = $nextRun;
            }
            if ($nextRun > $currentTime) {
                continue;
            }

            yield $jobSchedule->getJob();
        }

        $this->nextRun = $nearestNextRun;
    }
}
