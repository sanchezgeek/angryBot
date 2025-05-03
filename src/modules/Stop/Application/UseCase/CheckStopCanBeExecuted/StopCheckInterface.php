<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\CheckStopCanBeExecuted;

use App\Application\UseCase\Trading\Sandbox\Exception\Unexpected\UnexpectedSandboxExecutionException;
use App\Bot\Domain\Entity\Stop;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Dto\StopCheckResult;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Exception\TooManyTriesForCheckStop;
use App\Trading\Application\Check\Dto\TradingCheckContext;

interface StopCheckInterface
{
//    public function prepareContext(TradingCheckContext $context): void;
    public function supports(Stop $stop, TradingCheckContext $context): bool;

    /**
     * @throws TooManyTriesForCheckStop
     * @throws UnexpectedSandboxExecutionException
     */
    public function check(Stop $stop, TradingCheckContext $context): StopCheckResult;
}
