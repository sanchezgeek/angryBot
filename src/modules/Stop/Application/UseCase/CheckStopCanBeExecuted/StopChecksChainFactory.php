<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\CheckStopCanBeExecuted;

use App\Application\Logger\AppErrorLoggerInterface;
use App\Application\UseCase\Trading\Sandbox\Factory\SandboxStateFactory;
use App\Application\UseCase\Trading\Sandbox\Factory\TradingSandboxFactory;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Settings\Application\Service\AppSettingsProvider;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Checks\FurtherMainPositionLiquidation\FurtherMainPositionLiquidationCheck;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Checks\FurtherMainPositionLiquidation\FurtherMainPositionLiquidationCheckParameters;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final readonly class StopChecksChainFactory
{
    public function __construct(
        private AppSettingsProvider $settingsProvider,
        private AppErrorLoggerInterface $appErrorLogger,
        private PositionServiceInterface $positionService,
        private TradingSandboxFactory $sandboxFactory,
        private SandboxStateFactory $sandboxStateFactory,
        private RateLimiterFactory $checkCanCloseSupportWhilePushStopsThrottlingLimiter,
    ) {
    }

    public function full(): StopChecksChain
    {
        return new StopChecksChain(
            $this->appErrorLogger,
            new FurtherMainPositionLiquidationCheck(
                new FurtherMainPositionLiquidationCheckParameters($this->settingsProvider),
                $this->checkCanCloseSupportWhilePushStopsThrottlingLimiter,
                $this->positionService,
                $this->sandboxFactory,
                $this->sandboxStateFactory,
            )
        );
    }
}
