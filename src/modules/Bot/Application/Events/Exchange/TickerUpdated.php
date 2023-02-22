<?php

declare(strict_types=1);

namespace App\Bot\Application\Events\Exchange;

use App\Bot\Application\Events\LoggingEvent;
use App\Bot\Domain\Ticker;

final readonly class TickerUpdated implements LoggingEvent
{
    public function __construct(public Ticker $ticker)
    {
    }

    public function getLog(): string
    {
        return \sprintf('        %s: %.2f', $this->ticker->symbol->value, $this->ticker->indexPrice);
    }
}
