<?php

declare(strict_types=1);

namespace App\Tests\Helper;

use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation;
use App\Application\Messenger\Position\CheckPositionIsUnderLiquidationHandler;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Price\Price;
use App\Domain\Price\PriceRange;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;

class CheckLiquidationParametersHelper
{
    public const TRANSFER_FROM_SPOT_ON_DISTANCE = CheckPositionIsUnderLiquidationHandler::TRANSFER_FROM_SPOT_ON_DISTANCE;
    public const PERCENT_OF_LIQUIDATION_DISTANCE_TO_ADD_STOP = CheckPositionIsUnderLiquidationHandler::PERCENT_OF_LIQUIDATION_DISTANCE_TO_ADD_STOP_BEFORE;
    public const ACTUAL_STOPS_RANGE_FROM_ADDITIONAL_STOP = CheckPositionIsUnderLiquidationHandler::ACTUAL_STOPS_RANGE_FROM_ADDITIONAL_STOP;

    /**
     * @see CheckPositionIsUnderLiquidationHandler::transferFromSpotOnDistance
     */
    private static function transferFromSpotOnDistance(Ticker $ticker): float
    {
        return FloatHelper::modify(PnlHelper::convertPnlPercentOnPriceToAbsDelta(self::TRANSFER_FROM_SPOT_ON_DISTANCE, $ticker->indexPrice), 0.1);
    }

    /**
     * @see CheckPositionIsUnderLiquidationHandler::acceptableStoppedPart
     */
    public static function acceptableStoppedPart(CheckPositionIsUnderLiquidation $message): float
    {
        return $message->acceptableStoppedPart ?? CheckPositionIsUnderLiquidationHandler::ACCEPTABLE_STOPPED_PART;
    }

    /**
     * @see CheckPositionIsUnderLiquidationHandler::checkStopsOnDistance
     */
    public static function checkStopsOnDistance(Position $position): float
    {
        return self::additionalStopDistanceWithLiquidation($position) * 1.5;
    }

    /**
     * @see CheckPositionIsUnderLiquidationHandler::additionalStopDistanceWithLiquidation
     */
    public static function additionalStopDistanceWithLiquidation(Position $position): float
    {
        return FloatHelper::modify((new Percent(self::PERCENT_OF_LIQUIDATION_DISTANCE_TO_ADD_STOP))->of($position->liquidationDistance()), 0.1);
    }

    /**
     * @see CheckPositionIsUnderLiquidationHandler::getAdditionalStopPrice
     */
    public static function additionalStopPrice(Position $position): Price
    {
        $additionalStopDistanceWithLiquidation = self::additionalStopDistanceWithLiquidation($position);

        return (
            $position->isShort()
                ? $position->liquidationPrice()->sub($additionalStopDistanceWithLiquidation)
                : $position->liquidationPrice()->add($additionalStopDistanceWithLiquidation)
        );
    }

    /**
     * @see CheckPositionIsUnderLiquidationHandler::getActualStopsRange
     */
    public static function actualStopsRange(Position $position): PriceRange
    {
        $additionalStopPrice = self::additionalStopPrice($position);
        $modifier = FloatHelper::modify((new Percent(self::ACTUAL_STOPS_RANGE_FROM_ADDITIONAL_STOP))->of($position->liquidationDistance()), 0.1);

        return PriceRange::create($additionalStopPrice->sub($modifier), $additionalStopPrice->add($modifier));
    }

    /**
     * @see CheckPositionIsUnderLiquidationHandler::additionalStopTriggerDelta
     */
    public static function additionalStopTriggerDelta(Symbol $symbol): float
    {
        return FloatHelper::modify($symbol->stopDefaultTriggerDelta() * 3, 0.1);
    }
}
