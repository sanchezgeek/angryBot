<?php

declare(strict_types=1);

namespace App\Application\Cache;

use DateInterval;

/**
 * @example Just for handy autowiring
 */
abstract class AbstractCacheService implements CacheServiceInterface
{
    public function __construct(protected CacheServiceInterface $cache)
    {
    }

    public function remove(CacheKeyGeneratorInterface|string $key): void
    {
        $this->cache->remove($key);
    }

    public function get(CacheKeyGeneratorInterface|string $key, ?callable $warmup = null, DateInterval|int|null $ttl = null): mixed
    {
        return $this->cache->get($key, $warmup, $ttl ?? static::getDefaultTtl());
    }

    public function save(CacheKeyGeneratorInterface|string $key, mixed $value, DateInterval|int|null $ttl = null): void
    {
        $this->cache->save($key, $value, $ttl ?? static::getDefaultTtl());
    }

    protected static function getDefaultTtl(): ?int
    {
        return null;
    }

    public function clear(): void
    {
        $this->cache->clear();
    }
}
