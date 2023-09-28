<?php

namespace App\Bot\Domain\ValueObject\Order;

/**
 * @todo | Move to Infrastructure/ByBit
 */
enum ExecutionOrderType: string
{
    case Market = 'Market';
    case Limit = 'Limit';
}
