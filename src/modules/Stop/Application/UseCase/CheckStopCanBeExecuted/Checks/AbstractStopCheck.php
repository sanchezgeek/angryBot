<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\CheckStopCanBeExecuted\Checks;

use App\Helper\OutputHelper;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Dto\StopCheckResult;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\StopCheckInterface;

abstract class AbstractStopCheck implements StopCheckInterface
{
    public static function negativeResult(?string $reason = null): StopCheckResult
    {
        return StopCheckResult::negative(OutputHelper::shortClassName(static::class), $reason);
    }

    public static function positiveResult(string $reason): StopCheckResult
    {
        return StopCheckResult::positive(OutputHelper::shortClassName(static::class), $reason);
    }
}
