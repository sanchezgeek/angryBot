<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Logger;

use App\Infrastructure\Logger\SymfonyAppErrorLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

trait AppErrorsNullLoggerTrait
{
    static private ?LoggerInterface $testAppLogger = null;

    /**
     * @todo find __construct that must receive that
     */
    protected static function getNullLogger(): LoggerInterface
    {
        return new NullLogger();
    }

    protected static function getAppErrorLoggerWithInnerNullLogger(): SymfonyAppErrorLogger
    {
        return new SymfonyAppErrorLogger(self::getNullLogger());
    }
}
