<?php

namespace App\Infrastructure\ByBit\API\V5\Enum\Order;

enum ConditionalOrderTriggerDirection: string
{
    case RisesToTriggerPrice = '1';
    case FallsToTriggerPrice = '2';
}
