<?php

declare(strict_types=1);

namespace App\Liquidation\Domain\Assert;

enum SafePriceAssertionStrategyEnum: string
{
    case Aggressive = 'aggressive';
    case Moderate = 'moderate';
    case Conservative = 'conservative';
}
