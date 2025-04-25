<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5\Enum\Position;

enum PositionMode: string
{
    case SINGLE_SIDE_MODE = 'single';
    case BOTH_SIDES_MODE = 'both';
}
