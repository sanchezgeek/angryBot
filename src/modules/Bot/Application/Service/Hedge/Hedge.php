<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Hedge;

use App\Bot\Domain\Position;

final class Hedge
{
    public function __construct(
        public readonly Position $mainPosition,
        public readonly Position $supportPosition,
    ) {
    }

    public function isSupportPosition(Position $position): bool
    {
        return $this->supportPosition->side === $position->side;
    }
}
