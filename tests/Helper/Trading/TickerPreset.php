<?php

declare(strict_types=1);

namespace App\Tests\Helper\Trading;

use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Domain\Value\Percent\Percent;
use App\Tests\Factory\TickerFactory;

final class TickerPreset
{
    public static function notSoLongFromPositionEntry(Position $position, float $maxAllowedPercentPriceChange): Ticker
    {
        $entryPrice = $position->entryPrice();
        $delta = new Percent($maxAllowedPercentPriceChange)->of($position->entryPrice);

        $tickerPrice = $position->isShort() ? $entryPrice->sub($delta) : $entryPrice->add($delta);

        return TickerFactory::withEqualPrices($position->symbol, $tickerPrice->value());
    }

    public static function tooFarFromPositionEntry(Position $position, float $maxAllowedPercentPriceChange): Ticker
    {
        $entryPrice = $position->entryPrice();
        $delta = new Percent($maxAllowedPercentPriceChange + 0.01)->of($position->entryPrice);

        $tickerPrice = $position->isShort() ? $entryPrice->sub($delta) : $entryPrice->add($delta);

        return TickerFactory::withEqualPrices($position->symbol, $tickerPrice->value());
    }
}
