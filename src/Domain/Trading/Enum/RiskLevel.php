<?php

declare(strict_types=1);

namespace App\Domain\Trading\Enum;

/**
 * @todo | settings | DRY with SafePriceAssertionStrategyEnum?
 */
enum RiskLevel: string
{
    case Aggressive = 'aggressive';
    case Conservative = 'conservative';
    case Cautious = 'cautious';
}
