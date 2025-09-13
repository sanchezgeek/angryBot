<?php

declare(strict_types=1);

namespace App\Domain\Stop\Helper;

use App\Bot\Domain\Position;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Exception\PriceCannotBeLessThanZero;
use App\Domain\Price\Helper\PriceHelper;
use App\Domain\Price\SymbolPrice;
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
    public static function convertAbsDeltaToPnlPercentOnPrice(float $delta, float|SymbolPrice $onPrice): Percent
    {
        return new Percent($delta / SymbolPrice::toFloat($onPrice) * self::getPositionLeverage() * 100, false);
    }

    /**
     * @todo cover with tests (in case if method will not used by any other covered methods)
    */
    public static function convertPnlPercentOnPriceToAbsDelta(float|Percent $percent, float|SymbolPrice $onPrice): float
    {
        $precision = $onPrice instanceof SymbolPrice ? $onPrice->symbol->pricePrecision() : null;

        $percent = $percent instanceof Percent ? $percent->value() : $percent;

        $value = SymbolPrice::toFloat($onPrice) / self::getPositionLeverage();

        return PriceHelper::round(($percent / 100) * $value, $precision);
    }

    public static function getPnlInPercents(Position $position, float $price): float
    {
        $sign = $position->isShort() ? -1 : +1;
        $delta = $price - $position->entryPrice;

        return $sign * self::convertAbsDeltaToPnlPercentOnPrice($delta, $position->entryPrice)->value();
    }

    public static function getPnlInUsdt(Position $position, SymbolPrice|float $price, float $volume): float
    {
        $price = $price instanceof SymbolPrice ? $price->value() : $price;

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

    /**
     * @throws PriceCannotBeLessThanZero
     *
     * @todo | price | handle exception
     */
    public static function targetPriceByPnlPercentFromPositionEntry(Position $position, float $percent): SymbolPrice
    {
        return self::targetPriceByPnlPercent($position->entryPrice(), $percent, $position->side);
    }

    /**
     * @throws PriceCannotBeLessThanZero
     *
     * @todo | price | handle exception
     */
    public static function targetPriceByPnlPercent(SymbolPrice $fromPrice, float $percent, Side $forPositionSide): SymbolPrice
    {
        $sign = $forPositionSide->isShort() ? -1 : +1;

        $delta = self::convertPnlPercentOnPriceToAbsDelta($percent, $fromPrice);

        return SymbolPrice::create($fromPrice->add($sign * $delta)->value(), $fromPrice->symbol);
    }

    /**
     * @todo | profit | it's not about leverage. It's about fact profit that position give with certain contract size. Probably always must be = 100
     */
    protected static function getPositionLeverage(?Position $position = null): float
    {
        return 100; // @todo | use $position->positionLeverage ??;
    }

    public static function transformPriceChangeToPnlPercent(Percent|float $percent): Percent|float
    {
        if ($percent instanceof Percent) {
            return Percent::notStrict($percent->value()  * 100);
        }

        return $percent * 100;
    }
}
