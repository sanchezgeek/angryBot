<?php

namespace App\Bot\Domain\ValueObject\Order;

enum OrderType: string
{
    case Market = 'Market';
    case Limit = 'Limit';
}
