<?php

namespace App\Infrastructure\Cache;

use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;

interface TickersCache
{
    public function checkExternalTickerCacheOrUpdate(SymbolInterface $symbol, \DateInterval $ttl): void;
}
