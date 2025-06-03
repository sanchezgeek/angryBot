<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\Cache;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;

readonly final class UpdateTicker
{
    /** @var SymbolInterface[] */
    public array $symbols;

    public function __construct(public ?\DateInterval $ttl = null, SymbolInterface ...$symbols)
    {
        $this->symbols = $symbols;
    }
}
