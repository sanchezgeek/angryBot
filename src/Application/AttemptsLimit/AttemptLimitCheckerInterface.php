<?php

declare(strict_types=1);

namespace App\Application\AttemptsLimit;

interface AttemptLimitCheckerInterface
{
    public function attemptIsAvailable(): bool;
}
