<?php

declare(strict_types=1);

namespace App\Domain\Stop\Helper;

use App\Bot\Domain\Position;
use App\Domain\Price\Price;

/**
 * @see \App\Tests\Unit\Domain\Stop\Helper\PnlHelperTest
 */
final class PnlHelper
{
    public static function getPnlInPercents(Position $position, float $price): float
    {
        $sign = $position->isShort() ? -1 : +1;
        $delta = $price - $position->entryPrice;

        return $sign * ($delta / $position->entryPrice) * self::getPositionLeverage($position) * 100;
    }

    public static function getPnlInUsdt(Position $position, Price|float $price, float $volume): float
    {
        $price = $price instanceof Price ? $price->value() : $price;

        $sign = $position->side->isShort() ? -1 : +1;
        $delta = $price - $position->entryPrice;

        // @todo | or it's right only for BTCUSDT contracts?
        return $sign * $delta * $volume;
    }

    public static function getTargetPriceByPnlPercent(Position $position, float $percent): Price
    {
        $sign = $position->side->isShort() ? -1 : +1;

        $value = $position->entryPrice / self::getPositionLeverage($position);

        return Price::float(
            $position->entryPrice + ($sign * ($percent / 100) * $value)
        );
    }

    protected static function getPositionLeverage(Position $position): float
    {
        return 100; // @todo $position->positionLeverage ??;
    }
}
