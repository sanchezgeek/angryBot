<?php

declare(strict_types=1);

namespace App\Worker;

use function md5;
use function substr;
use function uniqid;

final class AppContext
{
    private static ?string $uniq = null;
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
            self::$workerAlias = RunningWorker::tryFrom($_ENV['RUNNING_WORKER']);
        }

        return self::$workerAlias;
    }

    public static function procNum(): int
    {
        return (int)$_ENV['PROCESS_NUM'];
    }
}
