<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Handler\FindAveragePriceChange;

use App\Application\Cache\AbstractCacheService;

final class AveragePriceChangeCache extends AbstractCacheService
{
    protected static function getDefaultTtl(): int
    {
        return 40000; // half of a day
    }
}
