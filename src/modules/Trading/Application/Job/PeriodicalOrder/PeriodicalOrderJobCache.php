<?php

declare(strict_types=1);

namespace App\Trading\Application\Job\PeriodicalOrder;

use App\Application\Cache\AbstractCacheService;
use DateTimeImmutable;

final class PeriodicalOrderJobCache extends AbstractCacheService
{
    protected static function getDefaultTtl(): null
    {
        return null;
    }

    public function getLastRun(string $task): ?DateTimeImmutable
    {
        $key = self::key($task);

        $value = $this->get($key);

        if ($value) {
            return $value;
        }

        return null;
    }

    public function saveLastRun(string $task, DateTimeImmutable $dateTimeImmutable): void
    {
        $this->save(self::key($task), $dateTimeImmutable);
    }

    private static function key(string $task): string
    {
        return md5($task);
    }
}
