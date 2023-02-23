<?php

declare(strict_types=1);

namespace App\Bot\Application\Events\Exchange;

use App\Bot\Application\Events\LoggableEvent;
use App\Bot\Domain\Position;

final class PositionUpdated extends LoggableEvent
{
    public function __construct(public readonly Position $position)
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
