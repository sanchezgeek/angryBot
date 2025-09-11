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
    protected static function getDefaultTtl(): DateTimeImmutable
    {
        return DateTimeHelper::nextHour();
    }

    public function getLastFundingRate(SymbolInterface $symbol): ?float
    {
        $key = sprintf('fundingRate_%s', $symbol->name());

        return $this->get($key);
    }

    public function setLastFundingRate(SymbolInterface $symbol, float $value): void
    {
        $key = sprintf('fundingRate_%s', $symbol->name());

        $this->save($key, $value);
    }
}
