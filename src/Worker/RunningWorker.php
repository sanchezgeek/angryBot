<?php

declare(strict_types=1);

namespace App\Worker;

enum RunningWorker: string
{
    case SERVICE = 'service';

    case BUY_ORDERS = 'buy-orders';

    case ASYNC_LOW = 'async_low';
    case ASYNC = 'async';
    case ASYNC_HIGH = 'async_high';
    case ASYNC_CRITICAL = 'async_critical';

    case CACHE = 'cache';
    case TICKERS_UPDATER = 'tickers_updater_async';

    case CRITICAL = 'critical';

    case MAIN_POSITIONS_STOPS = 'main-positions-stops';
    case REST_POSITIONS_STOPS = 'rest-positions-stops';
}
