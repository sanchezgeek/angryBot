<?php

declare(strict_types=1);

namespace App\Domain\Trading\Enum;

enum TradingStyle: string
{
    case Aggressive = 'aggressive';
    case Conservative = 'conservative';
    case Cautious = 'cautious';
}
