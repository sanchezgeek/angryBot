<?php

declare(strict_types=1);

namespace App\Bot\Domain\ValueObject\Order;

enum OrderType: string
{
    case Stop = 'Stop';
    case Add = 'Add';
}
