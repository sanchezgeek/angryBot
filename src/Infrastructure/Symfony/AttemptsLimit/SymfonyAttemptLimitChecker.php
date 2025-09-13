<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\AttemptsLimit;

use App\Application\AttemptsLimit\AttemptLimitCheckerInterface;
use Symfony\Component\RateLimiter\LimiterInterface;

final readonly class SymfonyAttemptLimitChecker implements AttemptLimitCheckerInterface
{
    public function __construct(
        private LimiterInterface $symfonyLimiter,
    ) {
    }

    public function attemptIsAvailable(): bool
    {
        return $this->symfonyLimiter->consume()->isAccepted();
    }
}
