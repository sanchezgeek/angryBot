<?php

declare(strict_types=1);

namespace App\Application\AttemptsLimit;

use Symfony\Component\RateLimiter\RateLimiterFactory;

interface AttemptLimitCheckerProviderInterface
{
    public function get(string $key, int $period, int $attemptsCount = 1): AttemptLimitCheckerInterface;
    public function getLimiterFactory(int $period, int $attemptsCount = 1, ?string $serviceId = null): RateLimiterFactory;
}
