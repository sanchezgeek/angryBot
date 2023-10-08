<?php

namespace App\Infrastructure\ByBit;

use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;

interface TickersCache
{
    public function updateTicker(Symbol $symbol, \DateInterval $ttl): Ticker;
}
