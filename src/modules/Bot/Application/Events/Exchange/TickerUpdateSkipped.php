<?php

declare(strict_types=1);

namespace App\Bot\Application\Events\Exchange;

use App\Bot\Application\Events\LoggableEvent;
use App\Infrastructure\ByBit\Service\CacheDecorated\Dto\CachedTickerDto;
use App\Worker\AppContext;

final class TickerUpdateSkipped extends LoggableEvent
{
    public function __construct(public readonly CachedTickerDto $foundCachedTickerDto)
    {
    }

    public function getLog(): ?string
    {
        if (AppContext::isTest()) {
            return null;
        }

        $ticker = $this->foundCachedTickerDto->ticker;
        $updatedBy = $this->foundCachedTickerDto->updatedByAccName;

        return \sprintf('%9s: %s (cache from %s)', $ticker->symbol->value, $ticker->indexPrice->value(), $updatedBy);
    }
}
