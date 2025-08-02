<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Check;

use App\Application\AttemptsLimit\AttemptLimitCheckerProviderInterface;
use App\Application\UseCase\Trading\Sandbox\Handler\UnexpectedSandboxExecutionExceptionHandler;
use App\Tests\Mixin\Attempts\AttemptLimitMixin;
use App\Tests\Mixin\Logger\AppErrorsNullLoggerTrait;

trait ChecksAwareTest
{
    use AppErrorsNullLoggerTrait;
    use AttemptLimitMixin;

    protected function getUnexpectedSandboxExecutionExceptionHandler(): UnexpectedSandboxExecutionExceptionHandler
    {
        if (method_exists(self::class, 'getContainer')) {
            $attemptsLimiter = self::getContainer()->get(AttemptLimitCheckerProviderInterface::class);
        } else {
            $attemptsLimiter = $this->attemptsCheckerProviderWithAllowedAttempts();
        }

        return new UnexpectedSandboxExecutionExceptionHandler($attemptsLimiter, self::getAppErrorLoggerWithInnerNullLogger());
    }
}
