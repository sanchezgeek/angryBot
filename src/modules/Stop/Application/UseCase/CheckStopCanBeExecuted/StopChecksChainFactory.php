<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\CheckStopCanBeExecuted;

use App\Application\Logger\AppErrorLoggerInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Checks\StopAndCheckFurtherMainPositionLiquidation;
use App\Trading\SDK\Check\Decorator\UseNegativeCachedResultWhileCheckDecorator;
use App\Trading\SDK\Check\Decorator\UseThrottlingWhileCheckDecorator;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final readonly class StopChecksChainFactory
{
    public function __construct(
        private AppErrorLoggerInterface $appErrorLogger,
        private PositionServiceInterface $positionService,
        private StopAndCheckFurtherMainPositionLiquidation $furtherMainPositionLiquidationCheck,
        private RateLimiterFactory $checkCanExecuteSupportStopThrottlingLimiter,
    ) {
    }

    public function full(): StopChecksChain
    {
        return new StopChecksChain(
            $this->positionService,
            $this->appErrorLogger,
            new UseThrottlingWhileCheckDecorator(
                new UseNegativeCachedResultWhileCheckDecorator($this->furtherMainPositionLiquidationCheck),
                $this->checkCanExecuteSupportStopThrottlingLimiter
            )
        );
    }
}
