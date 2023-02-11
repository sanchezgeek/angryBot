<?php

declare(strict_types=1);

namespace App\Bot\Application\MessageHandler;

use App\Bot\Domain\Position;

final class PositionData
{
    /**
     * Once per 30 seconds
     */
    private const UPDATE_INTERVAL = 30;

    public function __construct(
        public readonly ?Position $position,
        public ?\DateTimeImmutable $lastUpdated,
    ) {
    }

    public function needUpdate(\DateTimeImmutable $currentDatetime): bool
    {
        return !$this->lastUpdated
            || $currentDatetime->getTimestamp() - $this->lastUpdated->getTimestamp(
        ) > self::UPDATE_INTERVAL;
    }

    public function isPositionOpened(): bool
    {
        return $this->position !== null;
    }
}
