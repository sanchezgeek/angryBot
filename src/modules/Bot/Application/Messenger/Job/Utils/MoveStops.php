<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\Utils;

use App\Domain\Position\ValueObject\Side;

final readonly class MoveStops
{
    public function __construct(public Side $positionSide)
    {
    }
}
