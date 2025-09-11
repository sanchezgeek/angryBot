<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Handler\FindHighLowPrices;

use App\Application\Cache\AbstractCacheService;
use App\Application\Cache\CacheServiceInterface;
use App\Helper\DateTimeHelper;
use App\TechnicalAnalysis\Application\Cache\TechnicalAnalysisCacheInterface;
use DateTime;

final class FindHighLowPricesCache extends AbstractCacheService implements TechnicalAnalysisCacheInterface
{
    protected static function getDefaultTtl(): DateTime
    {
        return DateTimeHelper::nextHour();
    }

    public function replaceInnerCacheService(CacheServiceInterface $cacheService): void
    {
        $this->cache = $cacheService;
    }
}
