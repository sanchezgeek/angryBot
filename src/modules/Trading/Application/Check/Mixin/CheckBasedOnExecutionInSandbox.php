<?php

declare(strict_types=1);

namespace App\Trading\Application\Check\Mixin;

use App\Application\UseCase\Trading\Sandbox\Factory\SandboxStateFactoryInterface;
use App\Application\UseCase\Trading\Sandbox\Factory\TradingSandboxFactoryInterface;
use App\Trading\Application\Check\Dto\TradingCheckContext;

trait CheckBasedOnExecutionInSandbox
{
    private readonly TradingSandboxFactoryInterface $sandboxFactory;
    private readonly SandboxStateFactoryInterface $sandboxStateFactory;

    private function initSandboxServices(TradingSandboxFactoryInterface $sandboxFactory, SandboxStateFactoryInterface $sandboxStateFactory): void
    {
        $this->sandboxFactory = $sandboxFactory;
        $this->sandboxStateFactory = $sandboxStateFactory;
    }

    public function enrichContextWithCurrentSandboxState(TradingCheckContext $context): void
    {
        if (!$context->currentSandboxState) {
            $context->currentSandboxState = $this->sandboxStateFactory->byCurrentTradingAccountState($context->ticker->symbol);
        }
    }
}
