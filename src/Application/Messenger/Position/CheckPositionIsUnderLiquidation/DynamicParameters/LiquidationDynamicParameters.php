<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\DynamicParameters;

use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\CheckPositionIsUnderLiquidation;
use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\DynamicParameters\Exception\LiquidationDynamicParametersNotApplicapleException;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Domain\Price\Enum\PriceMovementDirection;
use App\Domain\Price\PriceRange;
use App\Domain\Price\SymbolPrice;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Trading\Enum\PredefinedStopLengthSelector;
use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;
use App\Liquidation\Application\Settings\LiquidationHandlerSettings;
use App\Liquidation\Application\Settings\WarningDistanceSettings;
use App\Settings\Application\Contract\AppDynamicParametersProviderInterface;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameter;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterEvaluations;
use App\Settings\Application\DynamicParameters\DefaultValues\DefaultValueProviderEnum;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use App\Worker\AppContext;
use LogicException;
use RuntimeException;

final class LiquidationDynamicParameters implements LiquidationDynamicParametersInterface, AppDynamicParametersProviderInterface
{
    public const float ACCEPTABLE_STOPPED_PART_DIVIDER = 3.5;
    public const PredefinedStopLengthSelector STOP_LENGTH_SELECTOR_FOR_CALCULATE_WARNING_RANGE = PredefinedStopLengthSelector::Long;

    private SymbolInterface $symbol;
    private ?float $warningDistanceRaw = null;
    private ?float $warningDistance = null;
    private ?PriceRange $criticalRange = null;
    private ?PriceRange $warningRange = null;

    private ?float $additionalStopDistanceWithLiquidation = null;
    private ?SymbolPrice $additionalStopPrice = null;
    private ?PriceRange $actualStopsPriceRange = null;

    /**
     * @throws LiquidationDynamicParametersNotApplicapleException
     */
    public function __construct(
        private readonly TradingParametersProviderInterface $tradingParametersProvider,

        private readonly AppSettingsProviderInterface $settingsProvider,

        #[AppDynamicParameterEvaluations(defaultValueProvider: DefaultValueProviderEnum::CurrentPositionState, skipUserInput: true)]
        private readonly Position $position,

        #[AppDynamicParameterEvaluations(defaultValueProvider: DefaultValueProviderEnum::CurrentTicker, skipUserInput: true)]
        private readonly Ticker $ticker,

        #[AppDynamicParameterEvaluations(defaultValueProvider: DefaultValueProviderEnum::LiquidationHandlerHandledMessage, skipUserInput: true)]
        private readonly ?CheckPositionIsUnderLiquidation $handledMessage = null,
    ) {
        if (AppContext::isParametersEvaluationContext() && (
            $this->position->isSupportPosition() || $this->position->getHedge()?->isEquivalentHedge())
        ) {
            throw new LiquidationDynamicParametersNotApplicapleException('Not applicable for support or equiv.hedge');
        }

        if (!$this->ticker->symbol->eq($this->position->symbol)) {
            throw new LogicException('Something wrong');
        }

        $this->symbol = $this->ticker->symbol;
    }

    #[AppDynamicParameter(group: 'liquidation-handler')]
    public function addOppositeBuyOrdersAfterStop(): bool
    {
        return $this->settingsProvider->required(
            SettingAccessor::withAlternativesAllowed(LiquidationHandlerSettings::AddOppositeBuyOrdersAfterStop, $this->symbol, $this->position->side)
        );
    }

    #[AppDynamicParameter(group: 'liquidation-handler')]
    public function checkStopsOnDistance(): float
    {
        $ticker = $this->ticker;

        if ($override = $this->handledMessage?->checkStopsOnPnlPercent) {
            return PnlHelper::convertPnlPercentOnPriceToAbsDelta($override, $ticker->markPrice);
        }

        return $this->symbol->makePrice($this->additionalStopDistanceWithLiquidation() * 1.5)->value();
    }

//    #[AppDynamicParameter(group: 'liquidation-handler')]
    public function additionalStopTriggerDelta(): float
    {
        return FloatHelper::modify($this->symbol->stopDefaultTriggerDelta() * 3, 0.1);
    }

    #[AppDynamicParameter(group: 'liquidation-handler')]
    public function additionalStopPrice(): SymbolPrice
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

    #[AppDynamicParameter(group: 'liquidation-handler')]
    public function criticalDistancePnl(): float
    {
        return $this->settingsProvider->required(
            SettingAccessor::withAlternativesAllowed(LiquidationHandlerSettings::CriticalDistancePnl, $this->symbol, $this->position->side)
        );
    }

    #[AppDynamicParameter(group: 'liquidation-handler')]
    public function criticalDistance(): float
    {
        // @todo | возможно надо брать position->entry при опред. обстоятельствах
        return PnlHelper::convertPnlPercentOnPriceToAbsDelta($this->criticalDistancePnl(), $this->ticker->markPrice);
    }

    #[AppDynamicParameter(group: 'liquidation-handler')]
    public function criticalRange(): PriceRange
    {
        if ($this->criticalRange === null) {
            $criticalDistance = $this->criticalDistance();
            $liquidationPrice = $this->position->liquidationPrice();

            $this->criticalRange = PriceRange::create(
                $liquidationPrice,
                $this->position->isShort() ? $liquidationPrice->sub($criticalDistance) : $liquidationPrice->add($criticalDistance),
                $this->symbol
            );
        }

        return $this->criticalRange;
    }

    #[AppDynamicParameter(group: 'liquidation-handler')]
    public function warningRange(): PriceRange
    {
        if ($this->warningRange === null) {
            $warningDistance = $this->warningDistance();
            $liquidationPrice = $this->position->liquidationPrice();

            $this->warningRange = PriceRange::create(
                $liquidationPrice,
                $this->position->isShort() ? $liquidationPrice->sub($warningDistance) : $liquidationPrice->add($warningDistance),
                $this->symbol
            );
        }

        return $this->warningRange;
    }

    #[AppDynamicParameter(group: 'liquidation-handler')]
    public function warningDistancePnlPercent(): float
    {
        if ($this->handledMessage?->warningPnlDistance !== null) {
            // @todo | liquidation | CheckPositionIsUnderLiquidationHandler | что-то придумать с моканьем в тестах (хотя в целом ничего не ломает)
            //      перенёс $this->criticalDistancePnl() из верхнего блока
            return max(
                $this->handledMessage?->warningPnlDistance,
                $this->criticalDistancePnl()
            );
        }

        // @todo | liquidation | CheckPositionIsUnderLiquidationHandler | нужно придумать что-то, чтобы точно не ликвиднуло (atr, maxWarningPnlPercent, ...)
        $max = $this->settingsProvider->required(
            SettingAccessor::withAlternativesAllowed(WarningDistanceSettings::WarningDistancePnlPercentMax, $this->symbol, $this->position->side)
        );

        $period = $this->settingsProvider->required(
            SettingAccessor::withAlternativesAllowed(WarningDistanceSettings::WarningDistancePnlPercentAtrPeriod, $this->symbol, $this->position->side)
        );

        $regular = PnlHelper::transformPriceChangeToPnlPercent(
            $this->tradingParametersProvider->regularPredefinedStopLength(
                symbol: $this->symbol,
                predefinedStopLength: self::STOP_LENGTH_SELECTOR_FOR_CALCULATE_WARNING_RANGE,
                period: $period
            )
        )->value();

        $multiplier = $this->settingsProvider->required(
            SettingAccessor::withAlternativesAllowed(WarningDistanceSettings::WarningDistancePnlPercentAtrMultiplier, $this->symbol, $this->position->side)
        );

        $regular *= $multiplier;

        $regular = min($regular, $max);

        return max($this->minimalWarningDistancePnlPercent(), $regular);
    }

    #[AppDynamicParameter(group: 'liquidation-handler')]
    public function minimalWarningDistancePnlPercent(): float
    {
        return max(
            $this->settingsProvider->required(
                SettingAccessor::withAlternativesAllowed(WarningDistanceSettings::WarningDistancePnl, $this->symbol, $this->position->side)
            ),
            $this->criticalDistancePnl() // foolproof
        );
    }

    #[AppDynamicParameter(group: 'liquidation-handler')]
    public function warningDistance(): float
    {// @todo | performance | settings | slow cache or db? or foreach // $start = OutputHelper::currentTimePoint();
        if ($this->warningDistance !== null) {
            return $this->warningDistance;
        }

        if ($this->position->isLiquidationPlacedBeforeEntry()) { # worst scenario
            $this->warningDistance =
                $this->warningDistanceRaw();
        } else {
            $this->warningDistance = max(
                $this->warningDistanceRaw(),
                new Percent($this->criticalPartOfLiquidationDistance())->of($this->position->liquidationDistance())
            );
        }

        return $this->warningDistance;
    }

    #[AppDynamicParameter(group: 'liquidation-handler')]
    public function warningDistanceRaw(): float
    {
        if ($this->warningDistanceRaw === null) {
            if ($this->handledMessage?->warningPnlDistance && !AppContext::isTest()) {
                throw new RuntimeException('Specifying of warningPnlDistance allowed only in test environment');
            }

            $priceToCalcAbsoluteDistance = $this->ticker->markPrice;
            $this->warningDistanceRaw = PnlHelper::convertPnlPercentOnPriceToAbsDelta($this->warningDistancePnlPercent(), $priceToCalcAbsoluteDistance);
        }

        return $this->warningDistanceRaw;
    }

    #[AppDynamicParameter(group: 'liquidation-handler')]
    public function criticalPartOfLiquidationDistance(): float|int
    {
        return $this->handledMessage?->criticalPartOfLiquidationDistance ?? $this->settingsProvider->required(
            SettingAccessor::withAlternativesAllowed(LiquidationHandlerSettings::CriticalPartOfLiquidationDistance, $this->symbol, $this->position->side)
        );
    }

    #[AppDynamicParameter(group: 'liquidation-handler')]
    public function percentOfLiquidationDistanceToAddStop(): Percent
    {
        if ($override = $this->handledMessage?->percentOfLiquidationDistanceToAddStop) {
            return new Percent($override, false);
        }

        return $this->settingsProvider->required(
            SettingAccessor::withAlternativesAllowed(LiquidationHandlerSettings::PercentOfLiquidationDistanceToAddStop, $this->symbol, $this->position->side)
        );
    }

//    #[AppDynamicParameter(group: 'liquidation-handler')]
    private function additionalStopDistanceWithLiquidation(bool $minWithTickerDistance = false): float
    {
        $position = $this->position;

        if ($this->additionalStopDistanceWithLiquidation === null) {
            $min = $this->warningDistance();

            if (!$this->position->isLiquidationPlacedBeforeEntry()) { # normal situation
                $this->additionalStopDistanceWithLiquidation = $this->percentOfLiquidationDistanceToAddStop()->of($position->liquidationDistance());

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

    #[AppDynamicParameter(group: 'liquidation-handler')]
    public function acceptableStoppedPartFallback(): float|null
    {
        return $this->settingsProvider->required(
            SettingAccessor::withAlternativesAllowed(LiquidationHandlerSettings::AcceptableStoppedPartOverride, $this->symbol, $this->position->side)
        );
    }

    #[AppDynamicParameter(group: 'liquidation-handler')]
    public function acceptableStoppedPart(): float
    {
        if ($override = $this->handledMessage?->acceptableStoppedPart) {
            return $override;
        }

        $ticker = $this->ticker;
        $position = $this->position;
        $distanceWithLiquidation = $position->priceDistanceWithLiquidation($ticker);
//            if (!$this->position->isLiquidationPlacedBeforeEntry()) { # normal situation} else { # bad scenario}
        if ($position->isPositionInLoss($ticker->markPrice)) {
            $additionalStopDistanceWithLiquidation = $this->additionalStopDistanceWithLiquidation(true);
            $initialDistanceWithLiquidation = $position->liquidationDistance();
            $distanceLeftInPercent = ($additionalStopDistanceWithLiquidation / $initialDistanceWithLiquidation) * 100;
            $acceptableStoppedPart = 100 - $distanceLeftInPercent;

            $priceToCalcModifier = $position->liquidationPrice()->modifyByDirection($position->side, PriceMovementDirection::TO_PROFIT, $additionalStopDistanceWithLiquidation);
            $currentDistanceWithLiquidationInPercentOfTickerPrice = PnlHelper::convertAbsDeltaToPnlPercentOnPrice($additionalStopDistanceWithLiquidation, $priceToCalcModifier)->value();
            $modifier = (100 / $currentDistanceWithLiquidationInPercentOfTickerPrice) * 7;
            if ($modifier > 1) {
                $modifier = 1;
            }

            // @todo | liquidation | manual | short liq distance / ticker right before liquidation (in critical range)
            // зафейлился: добавит объем в зависимости от той цены, которая где-то там выше

            return ($acceptableStoppedPart / self::ACCEPTABLE_STOPPED_PART_DIVIDER) * $modifier;
//            return ($acceptableStoppedPart / 1.5) * $modifier;
        } elseif ($distanceWithLiquidation <= $this->warningDistance()) {
            $additionalStopDistanceWithLiquidation = $position->priceDistanceWithLiquidation($ticker);
            $initialDistanceWithLiquidation = $this->warningDistance();
            $distanceLeftInPercent = ($additionalStopDistanceWithLiquidation / $initialDistanceWithLiquidation) * 100;
            $acceptableStoppedPart = 100 - $distanceLeftInPercent;

            $currentDistanceWithLiquidationInPercentOfTickerPrice = PnlHelper::convertAbsDeltaToPnlPercentOnPrice($additionalStopDistanceWithLiquidation, $ticker->markPrice)->value();
            $modifier = (100 / $currentDistanceWithLiquidationInPercentOfTickerPrice) * 7;
            if ($modifier > 1) {
                $modifier = 1;
            }

            return $acceptableStoppedPart * $modifier;
        }

        return $this->acceptableStoppedPartFallback();
    }

    #[AppDynamicParameter(group: 'liquidation-handler')]
    public function actualStopsRange(): PriceRange
    {
        if ($this->actualStopsPriceRange !== null) {
            return $this->actualStopsPriceRange;
        }

        $position = $this->position;
        $additionalStopPrice = $this->additionalStopPrice();

        $actualStopsRangeFromAdditionalStop = $this->settingsProvider->required(
            SettingAccessor::withAlternativesAllowed(LiquidationHandlerSettings::ActualStopsRangeFromAdditionalStop, $position->symbol, $position->side)
        );

        $modifierInitial = PnlHelper::convertPnlPercentOnPriceToAbsDelta($actualStopsRangeFromAdditionalStop, $additionalStopPrice);
        $modifierMax = PnlHelper::convertPnlPercentOnPriceToAbsDelta(100, $additionalStopPrice);
        $modifierMin = PnlHelper::convertPnlPercentOnPriceToAbsDelta(10, $additionalStopPrice);

        try {
            $modifier = $modifierInitial;

            if ($modifier < $modifierMin) {
                $modifier = $modifierMin;
            } elseif ($modifier > $modifierMax) {
                $modifier = $modifierMax;
            }
            $variableError = PnlHelper::convertPnlPercentOnPriceToAbsDelta(2, $additionalStopPrice);
//            if (self::isDebug()) var_dump($modifierMin, $modifierMax, $position->liquidationDistance(), $modifier, $additionalStopPrice);die;

            $markPrice = $this->ticker->markPrice->value();
            $liquidationPrice = $position->liquidationPrice();
            $criticalDistance = $this->criticalDistance();

            if ($position->isShort()) {
                $tickerSideBound = $additionalStopPrice->sub($modifier);
                $liquidationBound = min($additionalStopPrice->add($modifier)->value(), $liquidationPrice->sub($criticalDistance)->value());
                $liquidationBound = max($liquidationBound, $markPrice + $variableError);
            } else {
                $tickerSideBound = $additionalStopPrice->add($modifier);
                $liquidationBound = max($additionalStopPrice->sub($modifier)->value(), $liquidationPrice->add($criticalDistance)->value());
                $liquidationBound = min($liquidationBound, $markPrice - $variableError);
            }

            return $this->actualStopsPriceRange = PriceRange::create($tickerSideBound, $liquidationBound, $this->position->symbol);
        } catch (\Exception $e) {
            // @todo | liquidation | log into app_errors?
            if ($e->getMessage() === 'Price cannot be less than zero.') {
                $modifier = min($modifierInitial, $modifierMax);

                $this->actualStopsPriceRange = PriceRange::create($additionalStopPrice->sub($modifier), $additionalStopPrice->add($modifier), $this->symbol);
                var_dump(sprintf('LiquidationDynamicParameters / %s: %f - %f', $position->symbol->name(), $this->actualStopsPriceRange->from()->value(), $this->actualStopsPriceRange->to()->value()));

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
