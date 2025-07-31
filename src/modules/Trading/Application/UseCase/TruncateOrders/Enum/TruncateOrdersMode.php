<?php

declare(strict_types=1);

namespace App\Trading\Application\UseCase\TruncateOrders\Enum;

enum TruncateOrdersMode: string
{
    case Active = 'active';
    case All = 'all';
}
