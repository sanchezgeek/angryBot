<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Hedge;

use App\Bot\Domain\Position;

final class HedgeService
{
    public function getPositionsHedge(Position $a, Position $b): Hedge
    {
        if ($a->side === $b->side) {
            throw new \LogicException('Positions on the same side');
        }

        if ($a->size > $b->size) {
            $mainPosition = $a; $supportPosition = $b;
        } else {
            $mainPosition = $b; $supportPosition = $a;
        }

        return new Hedge($mainPosition, $supportPosition);
    }
}
