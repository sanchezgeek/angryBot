<?php

declare(strict_types=1);

namespace App\Worker;

enum RunningWorker: string
{
    case DEF = 'default';
    case CRON = 'cron';

    case SHORT = 'short';
    case LONG = 'long';

    case ASYNC = 'async';
    case CACHE = 'cache';
}
