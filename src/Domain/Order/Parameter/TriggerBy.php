<?php

namespace App\Domain\Order\Parameter;

enum TriggerBy: string
{
    case LastPrice = 'LastPrice';
    case IndexPrice = 'IndexPrice';
    case MarkPrice = 'MarkPrice';
}
