<?php

namespace App\Infrastructure\Cache;

use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;

interface TickersCache
{
    public function checkExternalTickerCacheOrUpdate(Symbol $symbol, \DateInterval $ttl): void;
}
