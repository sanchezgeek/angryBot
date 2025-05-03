<?php

declare(strict_types=1);

namespace App\Trading\Application\Check\Mixin;

use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Dto\StopCheckResult;
use App\Trading\Application\Check\Dto\TradingCheckContext;
use RuntimeException;

trait RequiresSandboxStateTrait
{
    private static function checkCurrentSandboxStateIsSet(TradingCheckContext $context): void
    {
        if (!$context->currentSandboxState) {
            throw new RuntimeException(
                sprintf('[%s] %s::$currentSandboxState must be set', __CLASS__, StopCheckResult::class)
            );
        }
    }
}
