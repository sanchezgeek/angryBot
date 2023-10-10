<?php

namespace App\Infrastructure\ByBit\API\V5\Enum\Order;

enum PositionIdx: int
{
    case OneWayMode = 0;
    case HedgeModeBuySide = 1;
    case HedgeModeSellSide = 2;
}
