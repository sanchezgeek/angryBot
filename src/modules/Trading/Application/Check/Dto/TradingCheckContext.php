<?php

declare(strict_types=1);

namespace App\Trading\Application\Check\Dto;

use App\Application\UseCase\Trading\Sandbox\SandboxStateInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;

final class TradingCheckContext
{
    private function __construct(
        public readonly Ticker $ticker,

        # for pre-checks (e.g. StopCheckInterface::supports)
        public ?Position $currentPositionState = null,

        # for deep checks
        public ?SandboxStateInterface $currentSandboxState = null,

        # if built for inner sandbox checks
        public bool $withoutThrottling = false
    ) {
    }

    public static function withTicker(Ticker $ticker): self
    {
        return new self($ticker);
    }

    public static function withCurrentPositionState(Ticker $ticker, Position $currentPositionState): self
    {
        return new self($ticker, $currentPositionState);
    }

    public static function full(Ticker $ticker, Position $currentPositionState, SandboxStateInterface $currentSandboxState): self
    {
        return new self($ticker, $currentPositionState, $currentSandboxState);
    }

    public function disableThrottling(): self
    {
        $this->withoutThrottling = true;

        return $this;
    }

    public function resetState(): void
    {
        $this->currentPositionState = null;
        $this->currentSandboxState = null;
    }
}
