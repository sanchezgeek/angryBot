<?php

declare(strict_types=1);

namespace App\Worker;

use function md5;
use function substr;
use function uniqid;

final class AppContext
{
    private static ?string $uniq = null;
    private static bool $debug = false;
    private static ?RunningWorker $workerAlias = null;

    public static function workerHash(): string
    {
        if (self::$uniq === null) {
            self::$uniq = substr(md5(uniqid('', true)), 0, 3);
        }

        return self::runningWorker()->value . '_' . self::$uniq;
    }

    public static function runningWorker(): RunningWorker
    {
        if (self::$workerAlias === null) {
            self::$workerAlias = RunningWorker::tryFrom($_ENV['RUNNING_WORKER']) ?? RunningWorker::DEFAULT;
        }

        return self::$workerAlias;
    }

    public static function procNum(): int
    {
        return (int)$_ENV['PROCESS_NUM'];
    }

    public static function isTest(): bool
    {
        return $_ENV['APP_ENV'] === 'test';
    }

    public static function accName(): ?string
    {
        return $_ENV['ACC_NAME'] ?? null;
    }

    public static function setIsDebug(bool $isDebug): void
    {
        self::$debug = $isDebug;
    }

    public static function isDebug(): bool
    {
        return self::$debug;
    }
}
