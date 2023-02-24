<?php

declare(strict_types=1);

namespace App\Bot\Application\Events\Exchange;

use App\Bot\Application\Events\LoggableEvent;
use App\Bot\Domain\Ticker;

final class TickerUpdated extends LoggableEvent
{
    public function __construct(public readonly Ticker $ticker, public readonly \DateTimeImmutable $requestedAt)
    {
    }

    public function getLog(): string
    {
        return \sprintf(
            '        %s: %.2f (requestedAt: %s)',
            $this->ticker->symbol->value,
            $this->ticker->indexPrice,
            $this->requestedAt->format('H:s.v'),
        );
    }
}
