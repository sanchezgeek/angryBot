<?php

declare(strict_types=1);

namespace App\Value;

final class CachedValue
{
    /**
     * 5 seconds
     */
    private const DEFAULT_TTL = 5000;

    private mixed $value;
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(
        private readonly \Closure $valueFactory,
        private readonly int $ttl = self::DEFAULT_TTL,
    ) {
    }

    public function get(): mixed
    {
        $needUpdate = $this->needUpdate();

        if ($needUpdate) {
            $this->value = ($this->valueFactory)();
            $this->updatedAt = \date_create_immutable();
        }

        return $this->value;
    }

    private function needUpdate(): bool
    {
        if ($this->updatedAt === null) {
            return true;
        }

        $now = (int)(new \DateTimeImmutable())->format('Uv');
        $updatedAt = (int)($this->updatedAt->format('Uv'));

        return $now - $updatedAt > $this->ttl;
    }
}
