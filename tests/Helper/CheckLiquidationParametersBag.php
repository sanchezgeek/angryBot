<?php

declare(strict_types=1);

namespace App\Tests\Helper;

use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\CheckPositionIsUnderLiquidation;
use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\CheckPositionIsUnderLiquidationHandler;
use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\DynamicParameters\LiquidationDynamicParameters;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Price\Enum\PriceMovementDirection;
use App\Domain\Price\Price;
use App\Domain\Price\PriceRange;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Value\Percent\Percent;
use App\Liquidation\Application\Settings\LiquidationHandlerSettings;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;
use InvalidArgumentException;
use RuntimeException;

class CheckLiquidationParametersBag
{
    /**
     * @see LiquidationDynamicParameters::ACCEPTABLE_STOPPED_PART_DIVIDER
     */
    public const ACCEPTABLE_STOPPED_PART_DIVIDER = 3.5;

    /**
     * @see CheckPositionIsUnderLiquidationParams::CRITICAL_DISTANCE_PNLS
     */
    private const CRITICAL_DISTANCE_PNLS = [
        Symbol::BTCUSDT->value => 60,
        Symbol::ETHUSDT->value => 80,
        Symbol::ARCUSDT->value => 200,
        'other' => 200
    ];

    public const TRANSFER_FROM_SPOT_ON_DISTANCE = CheckPositionIsUnderLiquidationHandler::TRANSFER_FROM_SPOT_ON_DISTANCE;

    private function __construct(
        private readonly AppSettingsProviderInterface $settingsProvider,
        private readonly CheckPositionIsUnderLiquidation $message,
        private readonly Position $position,
        private readonly ?Ticker $ticker,
    ) {
    }

    public static function create(AppSettingsProviderInterface $settingsProvider, CheckPositionIsUnderLiquidation $message, Position $position, ?Ticker $ticker = null): self
    {
        return new self($settingsProvider, $message, $position, $ticker);
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
     * @see LiquidationDynamicParameters::acceptableStoppedPart
     */
    public function acceptableStoppedPart(): float
    {
        if ($this->message->acceptableStoppedPart) {
            return $this->message->acceptableStoppedPart;
        }

        $ticker = $this->ticker;
        if ($ticker) {
            $position = $this->position;
            $distanceWithLiquidation = $position->priceDistanceWithLiquidation($ticker);
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

                return ($acceptableStoppedPart / self::ACCEPTABLE_STOPPED_PART_DIVIDER) * $modifier;
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
        }

        return $this->acceptableStoppedPartFallback();
    }

    /**
     * @see LiquidationDynamicParameters::acceptableStoppedPartFallback
     */
    public function acceptableStoppedPartFallback(): float|null
    {
        return $this->settingsProvider->required(
            SettingAccessor::withAlternativesAllowed(LiquidationHandlerSettings::AcceptableStoppedPartOverride, $this->position->symbol, $this->position->side)
        );
    }

    /**
     * @see LiquidationDynamicParameters::checkStopsOnDistance
     */
    public function checkStopsOnDistance(): float
    {
        return $this->additionalStopDistanceWithLiquidation() * 1.5;
    }

    /**
     * @see LiquidationDynamicParameters::additionalStopDistanceWithLiquidation
     */
    public function additionalStopDistanceWithLiquidation(bool $minWithTickerDistance = false): float
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

        if ($minWithTickerDistance && $this->ticker) {
            $minDistance = $this->ticker->markPrice->isPriceInRange($this->criticalRange())
                ? $this->criticalDistance()
                : $position->priceDistanceWithLiquidation($this->ticker);

            $additionalStopDistanceWithLiquidation = min($minDistance, $additionalStopDistanceWithLiquidation);
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

    public function criticalDistancePnl(): float
    {
        return self::CRITICAL_DISTANCE_PNLS[$this->position->symbol->value] ?? self::CRITICAL_DISTANCE_PNLS['other'];
    }

    public function criticalDistance(): float
    {
        return PnlHelper::convertPnlPercentOnPriceToAbsDelta($this->criticalDistancePnl(), $this->ticker?->markPrice ?? $this->position->liquidationPrice());
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

    /**
     * @see LiquidationDynamicParameters::warningDistance()
     */
    public function warningDistance(): float
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
     * @see LiquidationDynamicParameters::additionalStopPrice
     */
    public function additionalStopPrice(): Price
    {
        $additionalStopDistanceWithLiquidation = $this->additionalStopDistanceWithLiquidation(true);

        return (
            ($position = $this->position)->isShort()
                ? $position->liquidationPrice()->sub($additionalStopDistanceWithLiquidation)
                : $position->liquidationPrice()->add($additionalStopDistanceWithLiquidation)
        );
    }

    /**
     * @see LiquidationDynamicParameters::actualStopsRange
     */
    public function actualStopsRange(): PriceRange
    {
        $position = $this->position;
        $liquidationPrice = $position->liquidationPrice();

        $additionalStopPrice = $this->additionalStopPrice();
        $criticalDistance = $this->criticalDistance();

        $actualStopsRangeFromAdditionalStop = $this->settingsProvider->required(
            SettingAccessor::withAlternativesAllowed(LiquidationHandlerSettings::ActualStopsRangeFromAdditionalStop, $position->symbol, $position->side)
        );

        $modifier = (new Percent($actualStopsRangeFromAdditionalStop))->of($position->liquidationDistance());

        $min = PnlHelper::convertPnlPercentOnPriceToAbsDelta(50, $additionalStopPrice);
        $max = PnlHelper::convertPnlPercentOnPriceToAbsDelta(100, $additionalStopPrice);
        if ($modifier < $min) {
            $modifier = $min;
        } elseif ($modifier > $max) {
            $modifier = $max;
        }
        $variableError = PnlHelper::convertPnlPercentOnPriceToAbsDelta(2, $additionalStopPrice);

        if ($position->side->isShort()) {
            $tickerSideBound = $additionalStopPrice->sub($modifier);
            $liquidationBound = $additionalStopPrice->add($modifier);
            $liquidationBound = min($liquidationBound->value(), $liquidationPrice->sub($criticalDistance)->value());
            if ($this->ticker) {
                $liquidationBound = max($liquidationBound, $this->ticker->markPrice->value() + $variableError);
            }
        } else {
            $tickerSideBound = $additionalStopPrice->add($modifier);
            $liquidationBound = $additionalStopPrice->sub($modifier);
            $liquidationBound = max($liquidationBound->value(), $liquidationPrice->add($criticalDistance)->value());
            if ($this->ticker) {
                $liquidationBound = min($liquidationBound, $this->ticker->markPrice->value() - $variableError);
            }
        }

        return PriceRange::create($tickerSideBound, $liquidationBound, $this->position->symbol);
    }

    /**
     * @see LiquidationDynamicParameters::additionalStopTriggerDelta
     */
    public function additionalStopTriggerDelta(): float
    {
        return $this->position->symbol->stopDefaultTriggerDelta() * 3;
    }
}
