<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\PushStopsToTexchange\Dto;

final class PusStopContext
{
    public function __construct(
        private readonly Ticker $ticker,
    ) {
    }
}
