<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\CheckStopCanBeExecuted\Mixin;

use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Dto\StopCheckResult;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Dto\StopChecksContext;
use RuntimeException;

trait RequiresSandboxStateTrait
{
    private static function checkCurrentSandboxStateIsSet(StopChecksContext $context): void
    {
        if (!$context->currentSandboxState) {
            throw new RuntimeException(
                sprintf('[%s] %s::$currentSandboxState must be set', __CLASS__, StopCheckResult::class)
            );
        }
    }
}
