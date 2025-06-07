<?php

namespace App\Infrastructure\Cache;

use App\Trading\Domain\Symbol\SymbolInterface;

interface TickersCache
{
    public function checkExternalTickerCacheOrUpdate(SymbolInterface $symbol, \DateInterval $ttl): void;
}
