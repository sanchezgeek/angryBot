<?php

declare(strict_types=1);

namespace App\Tests\Helper;

use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation;
use App\Application\Messenger\Position\CheckPositionIsUnderLiquidationHandler;
use App\Application\Messenger\Position\CheckPositionIsUnderLiquidationParams;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Price\Price;
use App\Domain\Price\PriceRange;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Value\Percent\Percent;
use InvalidArgumentException;

class CheckLiquidationParametersHelper
{
    public const TRANSFER_FROM_SPOT_ON_DISTANCE = CheckPositionIsUnderLiquidationHandler::TRANSFER_FROM_SPOT_ON_DISTANCE;
    public const ACTUAL_STOPS_RANGE_FROM_ADDITIONAL_STOP = CheckPositionIsUnderLiquidationParams::ACTUAL_STOPS_RANGE_FROM_ADDITIONAL_STOP;

    /**
     * @see CheckPositionIsUnderLiquidationHandler::transferFromSpotOnDistance
     */
    private static function transferFromSpotOnDistance(Ticker $ticker): float
    {
        return PnlHelper::convertPnlPercentOnPriceToAbsDelta(self::TRANSFER_FROM_SPOT_ON_DISTANCE, $ticker->indexPrice);
    }

    /**
     * @see CheckPositionIsUnderLiquidationHandler::acceptableStoppedPart
     */
    public static function acceptableStoppedPart(CheckPositionIsUnderLiquidation $message): float
    {
        return $message->acceptableStoppedPart ?? CheckPositionIsUnderLiquidationParams::ACCEPTABLE_STOPPED_PART_DEFAULT;
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
            if (!$message->percentOfLiquidationDistanceToAddStop) {
                throw new InvalidArgumentException('In test environment `percentOfLiquidationDistanceToAddStop` must be set manually');
            }

            $additionalStopDistanceWithLiquidation = (new Percent($message->percentOfLiquidationDistanceToAddStop, false))->of($position->liquidationDistance());
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
        if (!$message->warningPnlDistance) {
            throw new InvalidArgumentException('In test environment `warningPnlDistance` must be set manually');
        }

        return $message->warningPnlDistance;
    }

    private static function warningDistance(CheckPositionIsUnderLiquidation $message, Position $position): float
    {
        $distancePnl = self::warningDistancePnl($message);

        if (!$position->isLiquidationPlacedBeforeEntry()) { # normal scenario
            $warningDistance = PnlHelper::convertPnlPercentOnPriceToAbsDelta($distancePnl, $position->entryPrice());
            $warningDistance = max($warningDistance, (new Percent(30))->of($position->liquidationDistance()));
        } else { # bad scenario
            $warningDistance = PnlHelper::convertPnlPercentOnPriceToAbsDelta($distancePnl, $position->entryPrice());
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
        $modifier = (new Percent(self::ACTUAL_STOPS_RANGE_FROM_ADDITIONAL_STOP))->of($position->liquidationDistance());

        return PriceRange::create($additionalStopPrice->sub($modifier), $additionalStopPrice->add($modifier));
    }

    /**
     * @see CheckPositionIsUnderLiquidationHandler::additionalStopTriggerDelta
     */
    public static function additionalStopTriggerDelta(Symbol $symbol): float
    {
        return $symbol->stopDefaultTriggerDelta() * 3;
    }
}
