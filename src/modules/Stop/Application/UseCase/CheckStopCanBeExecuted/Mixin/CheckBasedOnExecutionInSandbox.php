<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\CheckStopCanBeExecuted\Mixin;

use App\Application\UseCase\Trading\Sandbox\Factory\SandboxStateFactoryInterface;
use App\Application\UseCase\Trading\Sandbox\Factory\TradingSandboxFactoryInterface;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Dto\StopChecksContext;

trait CheckBasedOnExecutionInSandbox
{
    private readonly TradingSandboxFactoryInterface $sandboxFactory;
    private readonly SandboxStateFactoryInterface $sandboxStateFactory;

    private function initSandboxServices(TradingSandboxFactoryInterface $sandboxFactory, SandboxStateFactoryInterface $sandboxStateFactory): void
    {
        $this->sandboxFactory = $sandboxFactory;
        $this->sandboxStateFactory = $sandboxStateFactory;
    }

    public function enrichContextWithCurrentSandboxState(StopChecksContext $context): void
    {
        if (!$context->currentSandboxState) {
            $context->currentSandboxState = $this->sandboxStateFactory->byCurrentTradingAccountState($context->ticker->symbol);
        }
    }
}
