<?php

declare(strict_types=1);

namespace App\Profiling\SDK;

use JsonSerializable;

final readonly class ProfilingPointDto implements JsonSerializable
{
    public function __construct(
        public float $microTimestamp,
        public string $info,
        public ?ProfilingContext $context = null
    ) {
    }

    public static function create(string $info, ?ProfilingContext $context = null): self
    {
        return new self(microtime(true), $info, $context);
    }

    public static function fromStored(array $data): self
    {
        $context = ($contextData = $data['context']??null) ? ProfilingContext::fromStored($contextData) : null;

        return new self($data['timestamp'], $data['info'], $context);
    }

    public function toArray(): array
    {
        return ['timestamp' => $this->microTimestamp, 'info' => $this->info, 'context' => $this->context];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function timestampKey(): string
    {
        return sprintf('%.6f', $this->microTimestamp);
    }
}
