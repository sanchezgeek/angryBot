<?php

declare(strict_types=1);

namespace App\Tests\Helper;

use App\Application\Messenger\Position\CheckPositionIsUnderLiquidationHandler;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Stop\Helper\PnlHelper;
use App\Helper\FloatHelper;

class CheckLiquidationParametersHelper
{
    public const TRANSFER_FROM_SPOT_ON_DISTANCE = CheckPositionIsUnderLiquidationHandler::TRANSFER_FROM_SPOT_ON_DISTANCE;
    public const CHECK_STOPS_ON_DISTANCE = CheckPositionIsUnderLiquidationHandler::CHECK_STOPS_ON_DISTANCE;
    public const ADDITIONAL_STOP_DISTANCE_WITH_LIQUIDATION = CheckPositionIsUnderLiquidationHandler::ADDITIONAL_STOP_DISTANCE_WITH_LIQUIDATION;

    private static function transferFromSpotOnDistance(Ticker $ticker): float
    {
        return FloatHelper::modify(PnlHelper::convertPnlPercentOnPriceToAbsDelta(self::TRANSFER_FROM_SPOT_ON_DISTANCE, $ticker->indexPrice), 0.1);
    }

    /**
     * @see CheckPositionIsUnderLiquidationHandler::additionalStopDistanceWithLiquidation
     */
    public static function checkStopsDistance(Ticker $ticker): float
    {
        return FloatHelper::modify(PnlHelper::convertPnlPercentOnPriceToAbsDelta(self::CHECK_STOPS_ON_DISTANCE, $ticker->indexPrice), 0.1);
    }

    /**
     * @see CheckPositionIsUnderLiquidationHandler::additionalStopDistanceWithLiquidation
     */
    public static function additionalStopDistanceWithLiquidation(Position $position): float
    {
        return FloatHelper::modify(PnlHelper::convertPnlPercentOnPriceToAbsDelta(self::ADDITIONAL_STOP_DISTANCE_WITH_LIQUIDATION, $position->liquidationPrice()), 0.1);
    }

    /**
     * @see CheckPositionIsUnderLiquidationHandler::additionalStopTriggerDelta
     */
    public static function additionalStopTriggerDelta(Symbol $symbol): float
    {
        return FloatHelper::modify($symbol->stopDefaultTriggerDelta() * 10, 0.1);
    }
}