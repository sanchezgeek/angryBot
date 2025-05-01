<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\CheckStopCanBeExecuted\Dto;

final readonly class StopCheckResult
{
    private function __construct(
        public bool $success,
        public ?string $source = null,
        public ?string $reason = null
    ) {
    }

    public static function positive(string $source = null, ?string $reason = null): self
    {
        return new self(true, $source, $reason);
    }

    public static function negative(string $source, ?string $reason = null): self
    {
        return new self(false, $source, $reason);
    }

    public function resetReason(?string $reason = null): self
    {
        return new self($this->success, $this->source, $reason);
    }

    public function description(): ?string
    {
        if (!$this->reason) {
            return null;
        }

        return sprintf('%s%s: %s', $this->source ? $this->source . ' ' : '', $this->success ? 'SUCCEED' : 'FAILED', $this->reason);
    }
}
