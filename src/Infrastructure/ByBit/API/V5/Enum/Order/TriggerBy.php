<?php

namespace App\Infrastructure\ByBit\API\V5\Enum\Order;

enum TriggerBy: string
{
    case LastPrice = 'LastPrice';
    case IndexPrice = 'IndexPrice';
    case MarkPrice = 'MarkPrice';
}
