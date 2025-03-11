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
    public static function checkStopsOnDistance(CheckPositionIsUnderLiquidation $message, Position $position): float
    {
        return self::additionalStopDistanceWithLiquidation($message, $position) * 1.5;
    }

    /**
     * @see CheckPositionIsUnderLiquidationHandler::additionalStopDistanceWithLiquidation
     */
    public static function additionalStopDistanceWithLiquidation(CheckPositionIsUnderLiquidation $message, Position $position): float
    {
        if (!$position->isLiquidationPlacedBeforeEntry()) { # normal situation
            $distancePnl = $message->percentOfLiquidationDistanceToAddStop ?? self::PERCENT_OF_LIQUIDATION_DISTANCE_TO_ADD_STOP;
            $additionalStopDistanceWithLiquidation = FloatHelper::modify((new Percent($distancePnl, false))->of($position->liquidationDistance()), 0.15, 0.05);

            $additionalStopDistanceWithLiquidation = max($additionalStopDistanceWithLiquidation, self::warningDistance($message, $position));
        } else { # bad scenario
            // in this case using big position liquidationDistance may lead to add unnecessary stops
            // so just use some "warningDistance"
            $additionalStopDistanceWithLiquidation = self::warningDistance($message, $position);
        }

        return $additionalStopDistanceWithLiquidation;
    }

    public static function warningDistancePnl(CheckPositionIsUnderLiquidation $message): float
    {
        if ($message->warningPnlDistance) {
            $distance = $message->warningPnlDistance;
        } else {
            $symbol = $message->symbol;
            $distance = CheckPositionIsUnderLiquidationHandler::WARNING_PNL_DISTANCES[$symbol->value] ?? CheckPositionIsUnderLiquidationHandler::WARNING_PNL_DISTANCE_DEFAULT;
        }

        return $distance;
    }

    private static function warningDistance(CheckPositionIsUnderLiquidation $message, Position $position): float
    {
        $distancePnl = self::warningDistancePnl($message);

        if (!$position->isLiquidationPlacedBeforeEntry()) { # normal scenario
            $warningDistance = FloatHelper::modify(PnlHelper::convertPnlPercentOnPriceToAbsDelta($distancePnl, $position->entryPrice()), 0.1);
            $warningDistance = max($warningDistance, FloatHelper::modify((new Percent(30))->of($position->liquidationDistance()), 0.15, 0.05));
        } else { # bad scenario
            $warningDistance = FloatHelper::modify(PnlHelper::convertPnlPercentOnPriceToAbsDelta($distancePnl, $position->entryPrice()), 0.1);
        }

        return $warningDistance;
    }

    /**
     * @see CheckPositionIsUnderLiquidationHandler::getAdditionalStopPrice
     */
    public static function additionalStopPrice(CheckPositionIsUnderLiquidation $message, Position $position): Price
    {
        $additionalStopDistanceWithLiquidation = self::additionalStopDistanceWithLiquidation($message, $position);

        return (
            $position->isShort()
                ? $position->liquidationPrice()->sub($additionalStopDistanceWithLiquidation)
                : $position->liquidationPrice()->add($additionalStopDistanceWithLiquidation)
        );
    }

    /**
     * @see CheckPositionIsUnderLiquidationHandler::getActualStopsRange
     */
    public static function actualStopsRange(CheckPositionIsUnderLiquidation $message, Position $position): PriceRange
    {
        $additionalStopPrice = self::additionalStopPrice($message, $position);
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
