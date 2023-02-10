<?php

declare(strict_types=1);

namespace App\Messenger\SchedulerTransport;

use DateTimeImmutable;

interface JobScheduleInterface
{
    public function getNextRun(DateTimeImmutable $lastTick): ?DateTimeImmutable;

    public function getJob(): object;
}
