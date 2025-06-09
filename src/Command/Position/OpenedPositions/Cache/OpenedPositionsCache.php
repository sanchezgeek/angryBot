<?php

declare(strict_types=1);

namespace App\Command\Position\OpenedPositions\Cache;

use App\Application\Cache\AbstractCacheService;
use App\Bot\Domain\Position;
use RuntimeException;

final class OpenedPositionsCache extends AbstractCacheService
{
    private const string Manually_SavedDataKeysCacheKey = 'manually_saved_data_cache_keys';

    public function saveToCache(string $key, array $data): void
    {
        $this->cache->save($key, $data);
    }

    public function addToCache(string $cacheKey, string $dataKey, mixed $data): void
    {
        if (!($cachedData = $this->cache->get($cacheKey))) {
            throw new RuntimeException(sprintf('Cannot find stored cached data by "%s" key', $cacheKey));
        }

        $cachedData[$dataKey] = $data;

        $this->saveToCache($cacheKey, $cachedData);
    }

    public function getManuallySavedDataCacheKeys(): ?array
    {
        return $this->cache->get(self::Manually_SavedDataKeysCacheKey);
    }

    public function getCachedPositionItem(Position $position): PositionProxy|Position|null
    {
        return $this->cache->get(
            self::positionDataKey($position)
        );
    }

    public static function positionDataKey(Position $position): string
    {
        return sprintf('position_%s_%s', $position->symbol->name(), $position->side->value);
    }
}
