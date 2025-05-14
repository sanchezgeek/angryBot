<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Check;

use App\Application\UseCase\Trading\Sandbox\Handler\UnexpectedSandboxExecutionExceptionHandler;
use App\Tests\Mixin\Logger\AppErrorsNullLoggerTrait;
use App\Tests\Mixin\RateLimiterAwareTest;

trait ChecksAwareTest
{
    use RateLimiterAwareTest;
    use AppErrorsNullLoggerTrait;

    protected static function getUnexpectedSandboxExecutionExceptionHandler(): UnexpectedSandboxExecutionExceptionHandler
    {
        return new UnexpectedSandboxExecutionExceptionHandler(self::makeRateLimiterFactory(), self::getAppErrorLoggerWithInnerNullLogger());
    }
}
