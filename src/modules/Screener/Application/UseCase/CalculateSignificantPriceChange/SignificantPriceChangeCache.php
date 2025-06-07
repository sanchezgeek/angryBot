<?php

declare(strict_types=1);

namespace App\Screener\Application\UseCase\CalculateSignificantPriceChange;

use App\Application\Cache\AbstractCacheService;

final class SignificantPriceChangeCache extends AbstractCacheService
{
    protected static function getDefaultTtl(): int
    {
        return 40000; // half of day
    }
}
