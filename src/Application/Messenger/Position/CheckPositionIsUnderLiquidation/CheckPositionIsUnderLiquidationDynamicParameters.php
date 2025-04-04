<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position\CheckPositionIsUnderLiquidation;

use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\CheckPositionIsUnderLiquidationParams as Params;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Domain\Price\Enum\PriceMovementDirection;
use App\Domain\Price\Price;
use App\Domain\Price\PriceRange;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;

final class CheckPositionIsUnderLiquidationDynamicParameters
{
    private ?float $warningDistance = null;
    private ?float $additionalStopDistanceWithLiquidation = null;
    private ?Price $additionalStopPrice = null;
    private ?PriceRange $actualStopsPriceRange = null;

    public function __construct(
        private readonly CheckPositionIsUnderLiquidation $handledMessage,
        private readonly Position $position,
        private readonly Ticker $ticker,
    ) {
    }

    public function checkStopsOnDistance(): float
    {
        $message = $this->handledMessage;
        $ticker = $this->ticker;

        if ($message->checkStopsOnPnlPercent !== null) {
            return PnlHelper::convertPnlPercentOnPriceToAbsDelta($message->checkStopsOnPnlPercent, $ticker->markPrice);
        }

        return $ticker->symbol->makePrice($this->additionalStopDistanceWithLiquidation() * 1.5)->value();
    }

    public function additionalStopTriggerDelta(): float
    {
        return FloatHelper::modify($this->position->symbol->stopDefaultTriggerDelta() * 3, 0.1);
    }

    public function additionalStopPrice(): Price
    {
        if ($this->additionalStopPrice !== null) {
            return $this->additionalStopPrice;
        }

        $position = $this->position;
        $stopDistanceWithLiquidation = $this->additionalStopDistanceWithLiquidation(true);

        return $this->additionalStopPrice = (
        $position->isShort()
            ? $position->liquidationPrice()->sub($stopDistanceWithLiquidation)
            : $position->liquidationPrice()->add($stopDistanceWithLiquidation)
        );
    }

    public function warningDistance(): float
    {
        if ($this->warningDistance === null) {
            $distance = $this->handledMessage->warningPnlDistance ?? Params::warningDistancePnlDefault($this->handledMessage->symbol);
            $priceToCalcAbsoluteDistance = $this->ticker->markPrice;
//        $priceToCalcAbsoluteDistance = $this->position->entryPrice();

            if (!$this->position->isLiquidationPlacedBeforeEntry()) { # normal scenario
                $warningDistance = PnlHelper::convertPnlPercentOnPriceToAbsDelta($distance, $priceToCalcAbsoluteDistance);
                $this->warningDistance = max($warningDistance, (new Percent(30))->of($this->position->liquidationDistance()));
            } else { # bad scenario
                $warningDistance = PnlHelper::convertPnlPercentOnPriceToAbsDelta($distance, $priceToCalcAbsoluteDistance);
                $this->warningDistance = $warningDistance;
            }
        }

        return $this->warningDistance;
    }

    public function additionalStopDistanceWithLiquidation(bool $minWithTickerDistance = false): float
    {
        $position = $this->position;

        if ($this->additionalStopDistanceWithLiquidation === null) {
            if (!$this->position->isLiquidationPlacedBeforeEntry()) { # normal situation
                $distancePnl = $this->handledMessage->percentOfLiquidationDistanceToAddStop ?? Params::PERCENT_OF_LIQUIDATION_DISTANCE_TO_ADD_STOP_BEFORE;
                $this->additionalStopDistanceWithLiquidation = (new Percent($distancePnl, false))->of($position->liquidationDistance());

                $this->additionalStopDistanceWithLiquidation = max($this->additionalStopDistanceWithLiquidation, $this->warningDistance());
            } else { # bad scenario
                // in this case using big position liquidationDistance may lead to add unnecessary stops
                // so just use some "warningDistance"
                $this->additionalStopDistanceWithLiquidation = $this->warningDistance();
            }

            if ($minWithTickerDistance) {
                $this->additionalStopDistanceWithLiquidation = min(
                    $position->priceDistanceWithLiquidation($this->ticker),
                    $this->additionalStopDistanceWithLiquidation
                );
            }
        }

        return $this->additionalStopDistanceWithLiquidation;
    }

    public function acceptableStoppedPart(): float
    {
        if ($this->handledMessage->acceptableStoppedPart) {
            return $this->handledMessage->acceptableStoppedPart;
        }

        $ticker = $this->ticker;
        $position = $this->position;
        $distanceWithLiquidation = $position->priceDistanceWithLiquidation($ticker);
//            if (!$this->position->isLiquidationPlacedBeforeEntry()) { # normal situation} else { # bad scenario}
        if ($position->isPositionInLoss($ticker->markPrice)) {
            $additionalStopDistanceWithLiquidation = $this->additionalStopDistanceWithLiquidation(true);
            $initialDistanceWithLiquidation = $position->liquidationDistance();
            $distanceLeftInPercent = Percent::fromPart($additionalStopDistanceWithLiquidation / $initialDistanceWithLiquidation)->value();
            $acceptableStoppedPart = 100 - $distanceLeftInPercent;

            $priceToCalcModifier = $position->liquidationPrice()->modifyByDirection($position->side, PriceMovementDirection::TO_PROFIT, $additionalStopDistanceWithLiquidation);
            $currentDistanceWithLiquidationInPercentOfTickerPrice = PnlHelper::convertAbsDeltaToPnlPercentOnPrice($additionalStopDistanceWithLiquidation, $priceToCalcModifier)->value();
            $modifier = (100 / $currentDistanceWithLiquidationInPercentOfTickerPrice) * 7;
            if ($modifier > 1) {
                $modifier = 1;
            }

            return ($acceptableStoppedPart / Params::ACCEPTABLE_STOPPED_PART_DIVIDER) * $modifier;
//            return ($acceptableStoppedPart / 1.5) * $modifier;
        } elseif ($distanceWithLiquidation <= $this->warningDistance()) {
            $additionalStopDistanceWithLiquidation = $position->priceDistanceWithLiquidation($ticker);
            $initialDistanceWithLiquidation = $this->warningDistance();
            $distanceLeftInPercent = Percent::fromPart($additionalStopDistanceWithLiquidation / $initialDistanceWithLiquidation)->value();
            $acceptableStoppedPart = 100 - $distanceLeftInPercent;

            $currentDistanceWithLiquidationInPercentOfTickerPrice = PnlHelper::convertAbsDeltaToPnlPercentOnPrice($additionalStopDistanceWithLiquidation, $ticker->markPrice)->value();
            $modifier = (100 / $currentDistanceWithLiquidationInPercentOfTickerPrice) * 7;
            if ($modifier > 1) {
                $modifier = 1;
            }

            return $acceptableStoppedPart * $modifier;
        }

        return Params::ACCEPTABLE_STOPPED_PART_DEFAULT;
    }

    public function actualStopsRange(): PriceRange
    {
        if ($this->actualStopsPriceRange !== null) {
            return $this->actualStopsPriceRange;
        }

        $position = $this->position;

        $additionalStopPrice = $this->additionalStopPrice();

        // wtf?? liq. right after entry / ticker NOT in warn.range -> 30298.92-30299.08

        try {
            $modifier = (new Percent(Params::ACTUAL_STOPS_RANGE_FROM_ADDITIONAL_STOP))->of($position->liquidationDistance());
            $this->actualStopsPriceRange = PriceRange::create($additionalStopPrice->sub($modifier), $additionalStopPrice->add($modifier));

            return $this->actualStopsPriceRange;
        } catch (\Exception $e) {
            if ($e->getMessage() === 'Price cannot be less than zero.') {
                $modifier = min(
                    (new Percent(Params::ACTUAL_STOPS_RANGE_FROM_ADDITIONAL_STOP))->of($position->liquidationDistance()),
                    PnlHelper::convertPnlPercentOnPriceToAbsDelta(100, $additionalStopPrice)
                );

                $this->actualStopsPriceRange = PriceRange::create($additionalStopPrice->sub($modifier), $additionalStopPrice->add($modifier));
                var_dump(sprintf('%s: %f - %f', $position->symbol->value, $this->actualStopsPriceRange->from()->value(), $this->actualStopsPriceRange->to()->value()));

                return $this->actualStopsPriceRange;
            }
            throw $e;
        }

// @todo | mb $markPriceDifferenceWithIndexPrice?
//        $markPriceDifferenceWithIndexPrice = $ticker->markPrice->differenceWith($ticker->indexPrice);
//        return max(
//            $checkStopsCriticalDeltaWithLiquidation,
//            $markPriceDifferenceWithIndexPrice->isLossFor($position->side) ? $markPriceDifferenceWithIndexPrice->absDelta() : 0,
//        );
    }
}
