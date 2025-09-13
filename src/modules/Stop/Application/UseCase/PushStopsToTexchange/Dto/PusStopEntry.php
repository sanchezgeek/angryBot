<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\PushStopsToTexchange\Dto;

use App\Bot\Domain\Entity\Stop;

final class PusStopEntry
{
    public function __construct(
        private readonly Stop $stop,
        private readonly Ticker $ticker,
    ) {
    }
}
