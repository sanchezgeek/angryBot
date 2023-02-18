<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\Utils;

use App\Bot\Domain\ValueObject\Position\Side;

final readonly class MoveStopOrdersWhenPositionMoved
{
    public function __construct(public Side $positionSide)
    {
    }
}
