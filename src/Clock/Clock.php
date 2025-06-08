<?php

declare(strict_types=1);

namespace App\Clock;

use DateTimeImmutable;
use Symfony\Contracts\Service\ResetInterface;

/**
 * @codeCoverageIgnore - depends on global state - unreasonable to test.
 */
final class Clock implements ClockInterface
{
    private ?DateTimeImmutable $requestTime = null;

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    /**
     * phpcs:disable SlevomatCodingStandard.Variables.DisallowSuperGlobalVariable.DisallowedSuperGlobalVariable
     */
    public function request(): DateTimeImmutable
    {
        if ($this->requestTime !== null) {
            return $this->requestTime;
        }

        // Depends on global context REQUEST_TIME_FLOAT might be not set
        if (!isset($_SERVER['REQUEST_TIME_FLOAT'])) {
            return $this->requestTime = new DateTimeImmutable();
        }

        $requestTime = DateTimeImmutable::createFromFormat('U.u', (string) $_SERVER['REQUEST_TIME_FLOAT']);
        if (!$requestTime || !$requestTime->getTimestamp()) {
            $requestTime = new DateTimeImmutable();
        }

        return $this->requestTime = $requestTime;
    }

    public function reset(): void
    {
        $this->requestTime = null;
    }
}
