<?php

declare(strict_types=1);

namespace App\Bot\Domain\Exchange;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Position\ValueObject\Side;

final class ActiveStopOrder
{
    public function __construct(
        public readonly SymbolInterface $symbol,
        public readonly Side $positionSide,
        public readonly string $orderId,
        public readonly float $volume,
        public readonly float $triggerPrice,
        public readonly string $triggerBy,
    ) {
    }
}
