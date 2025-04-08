<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\DynamicParameters;

use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\CheckPositionIsUnderLiquidation;
use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\CheckPositionIsUnderLiquidationParams as Params;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Price\Enum\PriceMovementDirection;
use App\Domain\Price\Price;
use App\Domain\Price\PriceRange;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;
use App\Worker\AppContext;
use RuntimeException;

final class LiquidationDynamicParameters implements LiquidationDynamicParametersInterface
{
    private ?float $warningDistance = null;
    private ?PriceRange $warningRange = null;

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

    public function criticalDistancePnl(): float
    {
        return Params::criticalDistancePnlDefault($this->handledMessage->symbol);
    }

    public function criticalDistance(): float
    {
        // @todo | возможно надо брать position->entry при опред. обстоятельствах
        return PnlHelper::convertPnlPercentOnPriceToAbsDelta($this->criticalDistancePnl(), $this->ticker->markPrice);
    }

    public function criticalRange(): PriceRange
    {
        $criticalDistance = $this->criticalDistance();
        $liquidationPrice = $this->position->liquidationPrice();

        return PriceRange::create(
            $liquidationPrice,
            $this->position->isShort() ? $liquidationPrice->sub($criticalDistance) : $liquidationPrice->add($criticalDistance)
        );
    }

    public function warningRange(): PriceRange
    {
        if ($this->warningRange === null) {
            $warningDistance = $this->warningDistance();
            $liquidationPrice = $this->position->liquidationPrice();

            $this->warningRange = PriceRange::create(
                $liquidationPrice,
                $this->position->isShort() ? $liquidationPrice->sub($warningDistance) : $liquidationPrice->add($warningDistance)
            );
        }

        return $this->warningRange;
    }

    public function warningDistance(): float
    {
        if ($this->warningDistance === null) {
            if ($this->handledMessage->warningPnlDistance && !AppContext::isTest()) {
                throw new RuntimeException('Specifying of warningPnlDistance allowed only in test environment');
            }

            $priceToCalcAbsoluteDistance = $this->ticker->markPrice;
            $distancePnl = max(
                $this->handledMessage->warningPnlDistance ?? Params::warningDistancePnlDefault($this->handledMessage->symbol),
                $this->criticalDistancePnl() // foolproof
            );

            $warningDistance = PnlHelper::convertPnlPercentOnPriceToAbsDelta($distancePnl, $priceToCalcAbsoluteDistance);

            if (!$this->position->isLiquidationPlacedBeforeEntry()) { # normal scenario
                $this->warningDistance = max($warningDistance, (new Percent($this->criticalPartOfLiquidationDistance()))->of($this->position->liquidationDistance()));
            } else { # bad scenario
                $this->warningDistance = $warningDistance;
            }
        }

        return $this->warningDistance;
    }

    public function criticalPartOfLiquidationDistance(): float|int
    {
        return $this->handledMessage->criticalPartOfLiquidationDistance ?? Params::CRITICAL_PART_OF_LIQUIDATION_DISTANCE;
    }

    private function additionalStopDistanceWithLiquidation(bool $minWithTickerDistance = false): float
    {
        $position = $this->position;

        if ($this->additionalStopDistanceWithLiquidation === null) {
            $min = $this->warningDistance();

            if (!$this->position->isLiquidationPlacedBeforeEntry()) { # normal situation
                $distancePnl = $this->handledMessage->percentOfLiquidationDistanceToAddStop ?? Params::PERCENT_OF_LIQUIDATION_DISTANCE_TO_ADD_STOP_BEFORE;
                $this->additionalStopDistanceWithLiquidation = (new Percent($distancePnl, false))->of($position->liquidationDistance());

                $this->additionalStopDistanceWithLiquidation = max($this->additionalStopDistanceWithLiquidation, $min);
            } else { # bad scenario
                // in this case using big position liquidationDistance may lead to add unnecessary stops
                // so just use some "warningDistance"
                $this->additionalStopDistanceWithLiquidation = $min;
            }

            if ($minWithTickerDistance) {
                $minDistance = $this->ticker->markPrice->isPriceInRange($this->criticalRange())
                    ? $this->criticalDistance()
                    : $position->priceDistanceWithLiquidation($this->ticker);

                $this->additionalStopDistanceWithLiquidation = min($minDistance, $this->additionalStopDistanceWithLiquidation);
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

        try {
            $modifier = (new Percent(Params::ACTUAL_STOPS_RANGE_FROM_ADDITIONAL_STOP))->of($position->liquidationDistance());

            $min = PnlHelper::convertPnlPercentOnPriceToAbsDelta(50, $additionalStopPrice);
            $max = PnlHelper::convertPnlPercentOnPriceToAbsDelta(100, $additionalStopPrice);
            if ($modifier < $min) {
                $modifier = $min;
            } elseif ($modifier > $max) {
                $modifier = $max;
            }
            $variableError = PnlHelper::convertPnlPercentOnPriceToAbsDelta(2, $additionalStopPrice);

//            if (self::isDebug()) {
//                var_dump(
//                    $min,
//                    $max,
//                    $position->liquidationDistance(),
//                    $modifier,
//                    $additionalStopPrice
//                );die;
//            }

            $markPrice = $this->ticker->markPrice;
            $criticalDistance = $this->criticalDistance();
            if ($position->side->isShort()) {
                $tickerSideBound = $additionalStopPrice->sub($modifier);
                $liquidationBound = min($additionalStopPrice->add($modifier)->value(), $position->liquidationPrice()->sub($criticalDistance)->value());
                $liquidationBound = max($liquidationBound, $markPrice->value() + $variableError);
            } else {
                $tickerSideBound = $additionalStopPrice->add($modifier);
                $liquidationBound = max($additionalStopPrice->sub($modifier)->value(), $position->liquidationPrice()->add($criticalDistance)->value());
                $liquidationBound = min($liquidationBound, $markPrice->value() - $variableError);
            }

            return $this->actualStopsPriceRange = PriceRange::create($tickerSideBound, $liquidationBound, $this->position->symbol);
        } catch (\Exception $e) {
            if ($e->getMessage() === 'Price cannot be less than zero.') {
                $modifier = min(
                    (new Percent(Params::ACTUAL_STOPS_RANGE_FROM_ADDITIONAL_STOP))->of($position->liquidationDistance()),
                    PnlHelper::convertPnlPercentOnPriceToAbsDelta(100, $additionalStopPrice)
                );

                $this->actualStopsPriceRange = PriceRange::create($additionalStopPrice->sub($modifier), $additionalStopPrice->add($modifier));
                var_dump(sprintf('LiquidationDynamicParameters / %s: %f - %f', $position->symbol->value, $this->actualStopsPriceRange->from()->value(), $this->actualStopsPriceRange->to()->value()));

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

    public static function isDebug(): bool
    {
        return AppContext::isDebug() && AppContext::isTest();
    }
}
