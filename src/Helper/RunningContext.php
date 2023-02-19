<?php

declare(strict_types=1);

namespace App\Helper;

final class RunningContext
{
    private static ?string $uniq = null;

    public static function getRunningWorker(): string
    {
        if (self::$uniq === null) {
            self::$uniq = \substr(md5(\uniqid('', true)), 0, 3);
        }

        return $_ENV['RUNNING_WORKER'] . '_' . self::$uniq;
    }
}
