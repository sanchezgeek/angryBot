<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\CheckStopCanBeExecuted\Dto;

use LogicException;

final readonly class StopCheckResult
{
    private function __construct(
        public bool $success,
        public ?string $failedCheckClass = null,
        public ?string $reason = null
    ) {
        if ($this->success && ($this->failedCheckClass || $this->reason)) {
            throw new LogicException('failedCheckClass and reason must be empty for positive result');
        }

        if (!$this->success && !$this->failedCheckClass) {
            throw new LogicException('failedCheckClass and reason must be set for positive result');
        }
    }

    public static function positive(): self
    {
        return new self(true);
    }

    public static function negative(string $failedCheckClass, ?string $reason = null): self
    {
        return new self(false, $failedCheckClass, $reason);
    }
}
