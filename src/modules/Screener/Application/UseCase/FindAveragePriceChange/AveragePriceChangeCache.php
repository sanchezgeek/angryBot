<?php

declare(strict_types=1);

namespace App\Screener\Application\UseCase\FindAveragePriceChange;

use App\Application\Cache\AbstractCacheService;

final class AveragePriceChangeCache extends AbstractCacheService
{
    protected static function getDefaultTtl(): int
    {
        return 40000; // half of day
    }
}
