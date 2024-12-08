<?php

declare(strict_types=1);

namespace App\Bot\Application\Events\Exchange;

use App\Bot\Application\Events\LoggableEvent;
use App\Bot\Domain\Ticker;
use App\Worker\AppContext;

final class TickerUpdated extends LoggableEvent
{
    public function __construct(public readonly Ticker $ticker)
    {
    }

    public function getLog(): ?string
    {
        if (AppContext::isTest()) {
            return null;
        }
        return \sprintf('%s: %s', $this->ticker->symbol->value, $this->ticker->indexPrice->value());
    }
}
