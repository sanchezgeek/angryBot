<?php

namespace App\Infrastructure\ByBit\V5Api\Request\Trade\Enum;

enum TriggerByParam: string
{
    case LastPrice = 'LastPrice';
    case IndexPrice = 'IndexPrice';
    case MarkPrice = 'MarkPrice';
}
