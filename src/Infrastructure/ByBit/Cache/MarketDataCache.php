<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Cache;

use App\Application\Cache\AbstractCacheService;
use App\Helper\DateTimeHelper;
use App\Trading\Domain\Symbol\SymbolInterface;
use DateTime;
use DateTimeImmutable;

final class MarketDataCache extends AbstractCacheService
{
    protected static function getDefaultTtl(): DateTime
    {
        return DateTimeHelper::nextHour();
    }

    private static function fundingRateCacheKey(SymbolInterface $symbol): string
    {
        return sprintf('fundingRate_%s', $symbol->name());
    }

    public function getLastFundingRate(SymbolInterface $symbol): ?float
    {
        $key = self::fundingRateCacheKey($symbol);

        return $this->get($key);
    }

    public function setLastFundingRate(SymbolInterface $symbol, float $value): void
    {
        $key = self::fundingRateCacheKey($symbol);

        $this->save($key, $value);
    }
}
