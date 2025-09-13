<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

use App\Application\Cache\CacheKeyGeneratorInterface;
use App\Application\Cache\CacheServiceInterface;
use DateInterval;
use DateTimeInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class SymfonyCacheWrapper implements CacheServiceInterface
{
    public function __construct(private CacheInterface $cache)
    {
    }

    public function remove(CacheKeyGeneratorInterface|string $key): void
    {
        $key = $key instanceof CacheKeyGeneratorInterface ? $key->generate() : $key;

        $this->cache->delete($key);
    }

    public function get(CacheKeyGeneratorInterface|string $key, ?callable $warmup = null, DateInterval|DateTimeInterface|int|null $ttl = null): mixed
    {
        $key = $key instanceof CacheKeyGeneratorInterface ? $key->generate() : $key;

        $item = $this->cache->getItem($key);

        if ($item->isHit()) {
            return $item->get();
        }

        if ($warmup) {
            return $this->cache->get($key, function (ItemInterface $item) use ($ttl, $warmup) {
                if ($ttl instanceof DateTimeInterface) {
                    $item->expiresAt($ttl);
                } else {
                    $item->expiresAfter($ttl);
                }

                return $warmup();
            });
        }

        return null;
    }

    public function save(CacheKeyGeneratorInterface|string $key, mixed $value, DateInterval|DateTimeInterface|int|null $ttl = null): void
    {
        $key = $key instanceof CacheKeyGeneratorInterface ? $key->generate() : $key;

        $item = $this->cache->getItem($key)->set($value);
        if ($ttl instanceof DateTimeInterface) {
            $item->expiresAt($ttl);
        } else {
            $item->expiresAfter($ttl);
        }

        $this->cache->save($item);
    }

    public function clear(): void
    {
        $this->cache->clear();
    }
}
