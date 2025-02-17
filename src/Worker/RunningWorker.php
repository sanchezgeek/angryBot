<?php

declare(strict_types=1);

namespace App\Worker;

enum RunningWorker: string
{
    case SERVICE = 'service';

    case SHORT = 'short';
    case LONG = 'long';

    case ASYNC = 'async';
    case ASYNC_HIGH = 'async_high';
    case ASYNC_CRITICAL = 'async_critical';

    case UTILS = 'utils';
    case CACHE = 'cache';
}
