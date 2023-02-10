<?php

declare(strict_types=1);


namespace App\Bot\Domain\ValueObject\Position;

enum Side: string
{
    case Sell = 'sell';
    case Buy = 'buy';
}
