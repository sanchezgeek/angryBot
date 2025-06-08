<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Clock;

use App\Clock\ClockInterface;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;

trait ClockTimeAwareTester
{
    protected readonly ClockInterface|MockObject $clockMock;

    private static ?DateTimeImmutable $defaultCurrentClockTime = null;

    protected static function getCurrentClockTime(): DateTimeImmutable
    {
        if (self::$defaultCurrentClockTime !== null) {
            return self::$defaultCurrentClockTime;
        }

        return self::$defaultCurrentClockTime = new DateTimeImmutable();
    }

    /**
     * @before
     */
    public function initializeClock(): void
    {
        $this->clockMock = $this->createMock(ClockInterface::class);
        $this->clockMock->method('now')->willReturn(static::getCurrentClockTime());

        self::getContainer()->set(ClockInterface::class, $this->clockMock);
    }
}
