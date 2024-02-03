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

    public function getLog(): ?string
    {
        if ($this->position->isLong()) {
            return null;
        }

        return $this->position->value > 26500 ? 'true' : 'false';
//        return \sprintf('--#%s#-- | volume: %.2f USDT', $this->position->getCaption(), $this->position->positionValue);
    }
}
