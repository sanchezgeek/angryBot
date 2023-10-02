<?php

namespace App\Infrastructure\ByBit\API\V5\Request\Trade\Enum;

enum TriggerByParam: string
{
    case LastPrice = 'LastPrice';
    case IndexPrice = 'IndexPrice';
    case MarkPrice = 'MarkPrice';
}
