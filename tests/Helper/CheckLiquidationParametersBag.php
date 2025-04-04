<?php

declare(strict_types=1);

namespace App\Tests\Helper;

use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\CheckPositionIsUnderLiquidation;
use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\CheckPositionIsUnderLiquidationDynamicParameters;
use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\CheckPositionIsUnderLiquidationHandler;
use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\CheckPositionIsUnderLiquidationParams;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Domain\Price\Price;
use App\Domain\Price\PriceRange;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Value\Percent\Percent;
use InvalidArgumentException;
use RuntimeException;

class CheckLiquidationParametersBag
{
    public const TRANSFER_FROM_SPOT_ON_DISTANCE = CheckPositionIsUnderLiquidationHandler::TRANSFER_FROM_SPOT_ON_DISTANCE;
    public const ACTUAL_STOPS_RANGE_FROM_ADDITIONAL_STOP = CheckPositionIsUnderLiquidationParams::ACTUAL_STOPS_RANGE_FROM_ADDITIONAL_STOP;

    private function __construct(
        private readonly CheckPositionIsUnderLiquidation $message,
        private readonly Position $position,
        private readonly ?Ticker $ticker,
    ) {
    }

    public static function create(CheckPositionIsUnderLiquidation $message, Position $position, ?Ticker $ticker = null): self
    {
        return new self($message, $position, $ticker);
    }

    /**
     * @see CheckPositionIsUnderLiquidationHandler::transferFromSpotOnDistance
     */
    private function transferFromSpotOnDistance(): float
    {
        if (!$this->ticker) {
            throw new RuntimeException('CheckLiquidationParametersBag: wrong usage: ticker must be specified');
        }

        return PnlHelper::convertPnlPercentOnPriceToAbsDelta(self::TRANSFER_FROM_SPOT_ON_DISTANCE, $this->ticker->indexPrice);
    }

    /**
     * @see CheckPositionIsUnderLiquidationDynamicParameters::acceptableStoppedPart
     */
    public function acceptableStoppedPart(): float
    {
        return $this->message->acceptableStoppedPart ?? CheckPositionIsUnderLiquidationParams::ACCEPTABLE_STOPPED_PART_DEFAULT;
    }

    /**
     * @see CheckPositionIsUnderLiquidationDynamicParameters::checkStopsOnDistance
     */
    public function checkStopsOnDistance(): float
    {
        return $this->additionalStopDistanceWithLiquidation() * 1.5;
    }

    /**
     * @see CheckPositionIsUnderLiquidationDynamicParameters::additionalStopDistanceWithLiquidation
     */
    public function additionalStopDistanceWithLiquidation(): float
    {
        $position = $this->position;
        $message = $this->message;

        if (!$position->isLiquidationPlacedBeforeEntry()) { # normal situation
            if (!$message->percentOfLiquidationDistanceToAddStop) {
                throw new InvalidArgumentException('In test environment `percentOfLiquidationDistanceToAddStop` must be set manually');
            }

            $additionalStopDistanceWithLiquidation = (new Percent($message->percentOfLiquidationDistanceToAddStop, false))->of($position->liquidationDistance());
            $additionalStopDistanceWithLiquidation = max($additionalStopDistanceWithLiquidation, $this->warningDistance());
        } else { # bad scenario
            // in this case using big position liquidationDistance may lead to add unnecessary stops
            // so just use some "warningDistance"
            $additionalStopDistanceWithLiquidation = $this->warningDistance();
        }

        return $additionalStopDistanceWithLiquidation;
    }

    public function warningDistancePnl(): float
    {
        if (!$this->message->warningPnlDistance) {
            throw new InvalidArgumentException('In test environment `warningPnlDistance` must be set manually');
        }

        return $this->message->warningPnlDistance;
    }

    /**
     * @see CheckPositionIsUnderLiquidationDynamicParameters::warningDistance()
     */
    private function warningDistance(): float
    {
        $position = $this->position;

        $distancePnl = $this->warningDistancePnl();

        $priceToCalcAbsoluteDistance = $this->ticker?->markPrice ?? $position->entryPrice();

        if (!$position->isLiquidationPlacedBeforeEntry()) { # normal scenario
            $warningDistance = PnlHelper::convertPnlPercentOnPriceToAbsDelta($distancePnl, $priceToCalcAbsoluteDistance);
            $warningDistance = max($warningDistance, (new Percent(30))->of($position->liquidationDistance()));
        } else { # bad scenario
            $warningDistance = PnlHelper::convertPnlPercentOnPriceToAbsDelta($distancePnl, $priceToCalcAbsoluteDistance);
        }

        return $warningDistance;
    }

    /**
     * @see CheckPositionIsUnderLiquidationDynamicParameters::additionalStopPrice
     */
    public function additionalStopPrice(): Price
    {
        $additionalStopDistanceWithLiquidation = $this->additionalStopDistanceWithLiquidation();

        return (
            ($position = $this->position)->isShort()
                ? $position->liquidationPrice()->sub($additionalStopDistanceWithLiquidation)
                : $position->liquidationPrice()->add($additionalStopDistanceWithLiquidation)
        );
    }

    /**
     * @see CheckPositionIsUnderLiquidationDynamicParameters::actualStopsRange
     */
    public function actualStopsRange(): PriceRange
    {
        $additionalStopPrice = $this->additionalStopPrice();
        $modifier = (new Percent(self::ACTUAL_STOPS_RANGE_FROM_ADDITIONAL_STOP))->of($this->position->liquidationDistance());

        return PriceRange::create($additionalStopPrice->sub($modifier), $additionalStopPrice->add($modifier));
    }

    /**
     * @see CheckPositionIsUnderLiquidationDynamicParameters::additionalStopTriggerDelta
     */
    public function additionalStopTriggerDelta(): float
    {
        return $this->position->symbol->stopDefaultTriggerDelta() * 3;
    }
}
