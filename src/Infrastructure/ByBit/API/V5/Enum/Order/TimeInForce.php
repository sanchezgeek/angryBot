<?php

namespace App\Infrastructure\ByBit\API\V5\Enum\Order;

/**
 * @see https://bybit-exchange.github.io/docs/v5/order/create-order
 *
 * @todo | apiV5 | Research all types (for using 'Limit' vs 'Market' ExecutionOrderType)
 */
enum TimeInForce: string
{
    case GTC = 'GTC';
    case IOC = 'IOC';
    case FOK = 'FOK';
    case PostOnly = 'PostOnly';
}
