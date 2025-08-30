<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Handler\FindHighLowPrices;

use App\Application\Cache\AbstractCacheService;
use App\Application\Cache\CacheServiceInterface;
use App\TechnicalAnalysis\Application\Cache\TechnicalAnalysisCacheInterface;

final class FindHighLowPricesCache extends AbstractCacheService implements TechnicalAnalysisCacheInterface
{
    public function replaceInnerCacheService(CacheServiceInterface $cacheService): void
    {
        $this->cache = $cacheService;
    }

    protected static function getDefaultTtl(): int
    {
        return 10000;
    }
}
