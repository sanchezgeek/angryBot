<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\PushStopsToTexchange\Dto;

final readonly class PushStopResult
{
    public function __construct(
        public string $exchangeOrderId
    ) {
    }
}
