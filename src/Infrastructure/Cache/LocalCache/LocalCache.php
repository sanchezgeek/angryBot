<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache\LocalCache;

use App\Application\Cache\CacheKeyGeneratorInterface;
use App\Application\Cache\CacheServiceInterface;
use DateInterval;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class LocalCache implements CacheServiceInterface
{
    public function __construct(private CacheInterface $cache)
    {
    }

    public function get(CacheKeyGeneratorInterface|string $key, callable $warmup = null)
    {
        $key = $key instanceof CacheKeyGeneratorInterface ? $key->generate() : $key;

        $item = $this->cache->getItem($key);

        return $item->isHit() ? $item->get() : null;
    }

    public function save(CacheKeyGeneratorInterface|string $key, mixed $value, DateInterval|int|null $ttl = null): void
    {
        $key = $key instanceof CacheKeyGeneratorInterface ? $key->generate() : $key;

        $item = $this->cache->getItem($key)->set($value)->expiresAfter($ttl);

        $this->cache->save($item);
    }
}
