<?php

declare(strict_types=1);

namespace App\Application\Cache;

use DateInterval;

interface CacheServiceInterface
{
    public function remove(CacheKeyGeneratorInterface|string $key): void;
    public function get(CacheKeyGeneratorInterface|string $key, ?callable $warmup = null, DateInterval|int|null $ttl = null): mixed;
    public function save(CacheKeyGeneratorInterface|string $key, mixed $value, DateInterval|int|null $ttl = null): void;
    public function clear(): void;
}
