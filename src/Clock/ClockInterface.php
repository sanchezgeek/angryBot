<?php

declare(strict_types=1);

namespace App\Clock;

use DateTimeImmutable;

/**
 * Should be used for retrieve time instead of calls to globals
 */
interface ClockInterface
{
    /**
     * Returns a current time.
     * Changes on every call.
     */
    public function now(): DateTimeImmutable;

    /**
     * Returns the time of the current request start.
     * Doesn't change between calls with the same request.
     */
    public function request(): DateTimeImmutable;
}
