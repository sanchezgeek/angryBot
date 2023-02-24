<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\Cache;

use App\Bot\Domain\ValueObject\Symbol;

readonly final class WarmupTicker
{
    public function __construct(public Symbol $symbol)
    {
    }
}
