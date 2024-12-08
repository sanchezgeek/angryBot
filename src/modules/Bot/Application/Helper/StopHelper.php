<?php

declare(strict_types=1);

namespace App\Bot\Application\Helper;

use App\Bot\Application\Command\Exchange\TryReleaseActiveOrdersHandler;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Price\Price;

class StopHelper
{
    public static function getPriceModifierIfCurrentPriceOverStop(Price $currentPrice): float
    {
        return 0.0005 * $currentPrice->value();
    }

    public static function getAdditionalTriggerDeltaIfCurrentPriceOverStop(Symbol $symbol): float
    {
        return $symbol->makePrice($symbol->stopDefaultTriggerDelta() / 3)->value();
    }

    /**
     * @see TryReleaseActiveOrdersHandler $additionalTriggerDelta$defaultReleaseOverDistance
     */
    public static function defaultReleaseStopsDistance(Price $currentPrice): float
    {
        return $currentPrice->value() * 0.0015;
    }
}