<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Cache;

use App\Application\Cache\AbstractCacheService;
use App\Trading\Domain\Symbol\SymbolInterface;
use DateTime;

final class MarketDataCache extends AbstractCacheService
{
    protected static function getDefaultTtl(): DateTime
    {
        $date = new DateTime();
        $date->setTime((int)$date->format('H') + 1, 0, 0);

        return $date;
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
