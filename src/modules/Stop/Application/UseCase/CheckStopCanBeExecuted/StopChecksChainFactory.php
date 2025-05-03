<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\CheckStopCanBeExecuted;

use App\Application\Logger\AppErrorLoggerInterface;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Checks\StopAndCheckFurtherMainPositionLiquidation;

final readonly class StopChecksChainFactory
{
    public function __construct(
        private AppErrorLoggerInterface $appErrorLogger,
        private StopAndCheckFurtherMainPositionLiquidation $furtherMainPositionLiquidationCheck,
    ) {
    }

    public function full(): StopChecksChain
    {
        return new StopChecksChain(
            $this->appErrorLogger,
            $this->furtherMainPositionLiquidationCheck
        );
    }
}
