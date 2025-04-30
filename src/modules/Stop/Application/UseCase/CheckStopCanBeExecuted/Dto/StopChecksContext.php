<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\CheckStopCanBeExecuted\Dto;

use App\Application\UseCase\Trading\Sandbox\SandboxStateInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;

final class StopChecksContext
{
    private function __construct(
        public readonly Ticker $ticker,

        # for pre-checks (e.g. StopCheckInterface::supports)
        public ?Position $currentPositionState,

        # for deep checks
        public ?SandboxStateInterface $currentSandboxState = null,
    ) {
    }

    public static function create(Ticker $ticker, Position $currentPositionState): self
    {
        return new self($ticker, $currentPositionState);
    }

    public function resetState(): void
    {
        $this->currentPositionState = null;
        $this->currentSandboxState = null;
    }
//    public function replaceCurrentPositionState(Position $position): void
}
