<?php

declare(strict_types=1);

namespace App\Worker;

enum RunningWorker: string
{
    case DEF = 'default';

    case SHORT = 'short';
    case LONG = 'long';

    case ASYNC = 'async';

    case UTILS = 'utils';
    case CACHE = 'cache';
}
