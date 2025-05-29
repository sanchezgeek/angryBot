<?php

declare(strict_types=1);

namespace App\Application\Cache;

use DateInterval;

/**
 * @example Just for handy autowiring
 */
abstract class AbstractCacheService implements CacheServiceInterface
{
    public function __construct(private readonly CacheServiceInterface $cache)
    {
    }

    public function get(CacheKeyGeneratorInterface|string $key, ?callable $warmup = null, DateInterval|int|null $ttl = null): mixed
    {
        return $this->cache->get($key, $warmup, $ttl);
    }

    public function save(CacheKeyGeneratorInterface|string $key, mixed $value, DateInterval|int|null $ttl = null): void
    {
        $this->cache->save($key, $value, $ttl);
    }

    public function clear(): void
    {
        $this->cache->clear();
    }
}
