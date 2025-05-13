<?php

declare(strict_types=1);

namespace App\Trading\SDK\Check\Dto;

use App\Application\UseCase\Trading\Sandbox\SandboxStateInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Trading\SDK\Check\Decorator\UseThrottlingWhileCheckDecorator;

final class TradingCheckContext
{
    /**
     * @see UseThrottlingWhileCheckDecorator
     */
    public bool $withoutThrottling = false;

    private function __construct(
        public Ticker $ticker,

        /**
         * For pre-checks, e.g.
         * 1) TradingCheckInterface::supports
         * 2) MainPositionStopCheckInterface
         *    @see \App\Stop\Application\UseCase\CheckStopCanBeExecuted\MainPositionStopCheckInterface
         */
        public ?Position $currentPositionState = null,

        /**
         * For deep checks
         */
        public ?SandboxStateInterface $currentSandboxState = null,
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
