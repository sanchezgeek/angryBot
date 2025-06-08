<?php

declare(strict_types=1);

namespace App\Trading\Application\Symbol;

use App\Application\Cache\AbstractCacheService;

final class SymbolsCache extends AbstractCacheService
{
    protected static function getDefaultTtl(): ?int
    {
        return 86400;
    }
}
