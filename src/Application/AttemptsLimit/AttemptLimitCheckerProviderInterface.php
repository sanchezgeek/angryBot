<?php

declare(strict_types=1);

namespace App\Application\AttemptsLimit;

interface AttemptLimitCheckerProviderInterface
{
    public function get(string $key, int $period, int $attemptsCount = 1): AttemptLimitCheckerInterface;
}
