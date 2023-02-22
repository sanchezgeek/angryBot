<?php

declare(strict_types=1);

namespace App\Bot\Application\Events\Exchange;

use App\Bot\Application\Events\LoggingEvent;
use App\Bot\Domain\Position;

final readonly class PositionUpdated implements LoggingEvent
{
    public function __construct(public Position $position)
    {
    }

    public function getLog(): string
    {
        return \sprintf(
            '--#%s#-- | %.3f | $%.2f (liq: $%.2f | volume: %.2f USDT)',
            $this->position->getCaption(),
            $this->position->size,
            $this->position->entryPrice,
            $this->position->liquidationPrice,
            $this->position->positionValue,
        );
    }
}
