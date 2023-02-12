<?php

declare(strict_types=1);

namespace App\Bot\Application\Command;

use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Position\Side;

final class CreateBuyOrder
{
    public function __construct(
        public readonly int $id,
        public readonly Side $positionSide,
        public readonly float $volume,
        public readonly float $price,
        public readonly float $triggerDelta,
        public readonly array $context = [],
    ) {
    }
}
