<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\Cache;

use App\Bot\Domain\ValueObject\Symbol;

readonly final class UpdateTicker
{
    /** @var Symbol[] */
    public array $symbols;

    public function __construct(public ?\DateInterval $ttl = null, Symbol ...$symbols)
    {
        $this->symbols = $symbols;
    }
}
