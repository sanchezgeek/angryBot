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
    private static RunningWorker|null|false $runningWorker = false;
    private static ?TradingAccountType $accType = null;

    public static function workerHash(): ?string
    {
        if (!self::runningWorker()) {
            return null;
        }

        if (self::$uniq === null) {
            self::$uniq = substr(md5(uniqid('', true)), 0, 3);
        }

        return self::runningWorker()->value . '_' . self::$uniq;
    }

    public static function runningWorker(): ?RunningWorker
    {
        if (self::$runningWorker === false) {
            self::$runningWorker = RunningWorker::tryFrom($_ENV['RUNNING_WORKER'] ?? '');
        }

        return self::$runningWorker;
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

    /**
     * @todo | UTA | Temporarily solution | Or not? =)
     */
    public static function accType(): TradingAccountType
    {
        if (self::$accType === null) {
            self::$accType = TradingAccountType::tryFrom($_ENV['ACCOUNT_TYPE']) ?? TradingAccountType::CLASSIC;
        }

        return self::$accType;
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
