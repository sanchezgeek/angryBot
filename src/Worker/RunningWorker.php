<?php

declare(strict_types=1);

namespace App\Worker;

enum RunningWorker: string
{
    /** @deprecated @see symbol-consumer-template */
    case SYMBOL_DEDICATED = 'symbol-dedicated';

    case SERVICE = 'service';

    case BUY_ORDERS = 'buy-orders';

    case ASYNC = 'async';
    case ASYNC_HIGH = 'async_high';
    case ASYNC_CRITICAL = 'async_critical';

    case UTILS = 'utils';
    case CACHE = 'cache';

    case CRITICAL = 'critical';

    case MAIN_POSITIONS_STOPS = 'main-positions-stops';
    case REST_POSITIONS_STOPS = 'rest-positions-stops';
}
