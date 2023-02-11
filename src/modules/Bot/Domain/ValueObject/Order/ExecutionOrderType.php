<?php

namespace App\Bot\Domain\ValueObject\Order;

enum ExecutionOrderType: string
{
    case Market = 'Market';
    case Limit = 'Limit';
}
