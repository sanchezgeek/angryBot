<?php

declare(strict_types=1);

namespace App\Bot\Application\Helper;

use App\Bot\Application\Command\Exchange\TryReleaseActiveOrdersHandler;
use App\Domain\Price\SymbolPrice;
use App\Trading\Domain\Symbol\SymbolInterface;

class StopHelper
{
    public static function priceModifierIfCurrentPriceOverStop(SymbolPrice $currentPrice): float
    {
        return 0.0005 * $currentPrice->value();
    }

    public static function additionalTriggerDeltaIfCurrentPriceOverStop(SymbolInterface $symbol): float
    {
        return $symbol->makePrice($symbol->stopDefaultTriggerDelta() / 3)->value();
    }

    /**
     * @see TryReleaseActiveOrdersHandler $additionalTriggerDelta$defaultReleaseOverDistance
     */
    public static function defaultReleaseStopsDistance(SymbolPrice $currentPrice): float
    {
        return $currentPrice->value() * 0.0015;
    }
}
