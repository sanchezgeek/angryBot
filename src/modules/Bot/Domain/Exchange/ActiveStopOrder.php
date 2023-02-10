<?php

declare(strict_types=1);

namespace App\Bot\Domain\Exchange;

use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Domain\ValueObject\Symbol;

final class ActiveStopOrder
{
    public function __construct(
        public readonly Symbol $symbol,
        public readonly Side $positionSide,
        public readonly string $orderId,
        public readonly float $volume,
        public readonly float $triggerPrice,
        public readonly string $triggerBy,
    ) {
    }
}
