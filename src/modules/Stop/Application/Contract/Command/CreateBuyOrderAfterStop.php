<?php

declare(strict_types=1);

namespace App\Stop\Application\Contract\Command;

final class CreateBuyOrderAfterStop
{
    public function __construct(
        public int $stopId,
        public float $prevPositionSize
    ) {
    }
}
