<?php

declare(strict_types=1);


namespace App\Domain\BuyOrder\Enum;

enum BuyOrderState: string
{
    case Idle = 'idle';
    case Active = 'active';
}
