<?php

namespace App\Infrastructure\ByBit\API\V5\Request\Trade\Enum;

/**
 * @see https://bybit-exchange.github.io/docs/v5/order/create-order
 *
 * @todo | Research all types (for using 'Limit' vs 'Market' ExecutionOrderType)
 */
enum TimeInForceParam: string
{
    case GTC = 'GTC';
    case IOC = 'IOC';
    case FOK = 'FOK';
    case PostOnly = 'PostOnly';
}
