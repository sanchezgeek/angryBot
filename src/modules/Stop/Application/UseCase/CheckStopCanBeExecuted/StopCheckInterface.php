<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\CheckStopCanBeExecuted;

use App\Bot\Domain\Entity\Stop;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Dto\StopCheckResult;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Dto\StopChecksContext;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Exception\TooManyTriesForCheckStop;

interface StopCheckInterface
{
//    public function prepareContext(StopChecksContext $context): void;
    public function supports(Stop $stop, StopChecksContext $context): bool;

    /**
     * @throws TooManyTriesForCheckStop
     */
    public function check(Stop $stop, StopChecksContext $context): StopCheckResult;
}
