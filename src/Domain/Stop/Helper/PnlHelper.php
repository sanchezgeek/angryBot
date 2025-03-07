<?php

declare(strict_types=1);

namespace App\Domain\Stop\Helper;

use App\Bot\Domain\Position;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Helper\PriceHelper;
use App\Domain\Price\Price;
use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;

/**
 * @see \App\Tests\Unit\Domain\Stop\Helper\PnlHelperTest
 */
final class PnlHelper
{
    /**
     * @todo cover with tests (in case if method will not used by any other covered methods)
     */
    public static function convertAbsDeltaToPnlPercentOnPrice(float $delta, float|Price $onPrice): Percent
    {
        return new Percent($delta / Price::toFloat($onPrice) * self::getPositionLeverage() * 100, false);
    }

    /**
     * @todo cover with tests (in case if method will not used by any other covered methods)
    */
    public static function convertPnlPercentOnPriceToAbsDelta(float|Percent $percent, Price $onPrice): float
    {
        $percent = $percent instanceof Percent ? $percent : new Percent($percent, false);

        $value = Price::toFloat($onPrice) / self::getPositionLeverage();

        return PriceHelper::round($percent->part() * $value, $onPrice->precision);
    }

    public static function getPnlInPercents(Position $position, float $price): float
    {
        $sign = $position->isShort() ? -1 : +1;
        $delta = $price - $position->entryPrice;

        return $sign * self::convertAbsDeltaToPnlPercentOnPrice($delta, $position->entryPrice)->value();
    }

    public static function getPnlInUsdt(Position $position, Price|float $price, float $volume): float
    {
        $price = $price instanceof Price ? $price->value() : $price;

        $sign = $position->side->isShort() ? -1 : +1;
        $delta = $price - $position->entryPrice;

        // @todo | or it's right only for BTCUSDT contracts?
        return $sign * $delta * $volume;
    }

    /**
     * @todo | tests
     */
    public static function getVolumeForGetWishedProfit(float $pnlInUsdt, float $priceDelta): float
    {
        return $pnlInUsdt / $priceDelta;
    }

    public static function targetPriceByPnlPercentFromPositionEntry(Position $position, float $percent): Price
    {
        return self::targetPriceByPnlPercent($position->entryPrice(), $percent, $position);
    }

    public static function targetPriceByPnlPercent(Price $fromPrice, float $percent, Position $position): Price
    {
        $sign = $position->isShort() ? -1 : +1;

        $delta = self::convertPnlPercentOnPriceToAbsDelta($percent, $fromPrice);

        return Price::float($fromPrice->add($sign * $delta)->value(), $position->symbol->pricePrecision());
    }

    protected static function getPositionLeverage(?Position $position = null): float
    {
        return 100; // @todo | use $position->positionLeverage ??;
    }
}
