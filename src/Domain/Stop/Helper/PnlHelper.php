<?php

declare(strict_types=1);

namespace App\Domain\Stop\Helper;

use App\Bot\Domain\Position;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Price;
use App\Domain\Value\Percent\Percent;

/**
 * @see \App\Tests\Unit\Domain\Stop\Helper\PnlHelperTest
 */
final class PnlHelper
{
    public static function convertAbsDeltaToPnlPercentRelativeToPrice(float $delta, float|Price $relativeToPrice): Percent
    {
        return new Percent($delta / Price::toFloat($relativeToPrice) * self::getPositionLeverage() * 100, false);
    }

    public static function getPnlInPercents(Position $position, float $price): float
    {
        $sign = $position->isShort() ? -1 : +1;
        $delta = $price - $position->entryPrice;

        return $sign * self::convertAbsDeltaToPnlPercentRelativeToPrice($delta, $position->entryPrice)->value();
    }

    public static function getPnlInUsdt(Position $position, Price|float $price, float $volume): float
    {
        $price = $price instanceof Price ? $price->value() : $price;

        $sign = $position->side->isShort() ? -1 : +1;
        $delta = $price - $position->entryPrice;

        // @todo | or it's right only for BTCUSDT contracts?
        return $sign * $delta * $volume;
    }

    public static function targetPriceByPnlPercentFromPositionEntry(Position $position, float $percent): Price
    {
        return self::targetPriceByPnlPercent($position->entryPrice, $percent, $position);
    }

    public static function targetPriceByPnlPercent(Price|float $fromPrice, float $percent, Position $position): Price
    {
        $fromPrice = $fromPrice instanceof Price ? $fromPrice->value() : $fromPrice;
        $sign = $position->isShort() ? -1 : +1;

        $value = $fromPrice / self::getPositionLeverage($position);

        return Price::float(
            $fromPrice + ($sign * ($percent / 100) * $value)
        );
    }

    protected static function getPositionLeverage(?Position $position = null): float
    {
        return 100; // @todo | use $position->positionLeverage ??;
    }
}
