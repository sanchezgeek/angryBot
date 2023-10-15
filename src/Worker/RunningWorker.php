<?php

declare(strict_types=1);

namespace App\Worker;

enum RunningWorker: string
{
    case DEFAULT = 'default';

    case SHORT = 'short';
    case LONG = 'long';

    case ASYNC = 'async';
    case ASYNC_HIGH = 'async_high';

    case UTILS = 'utils';
    case CACHE = 'cache';
}
