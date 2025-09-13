<?php

declare(strict_types=1);

namespace App\Trading\Application\UseCase\TruncateOrders\Enum;

enum TruncateOrdersType: string
{
    case Stops = 'sl';
    case Buy = 'buy';
    case All = 'all';
}
