<?php

declare(strict_types=1);

namespace App\Trading\Application\Parameters;

use App\Bot\Application\Settings\TradingSettings;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Trading\Enum\PriceDistanceSelector;
use App\Domain\Trading\Enum\TimeFrame;
use App\Domain\Trading\Enum\RiskLevel;
use App\Domain\Value\Percent\Percent;
use App\Liquidation\Domain\Assert\SafePriceAssertionStrategyEnum;
use App\Screener\Application\Settings\PriceChangeSettings;
use App\Settings\Application\Contract\AppDynamicParametersProviderInterface;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameter;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterAutowiredArgument;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterEvaluations;
use App\Settings\Application\DynamicParameters\DefaultValues\DefaultValueProviderEnum;
use App\Settings\Application\Helper\SettingsHelper;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;
use App\TechnicalAnalysis\Application\Contract\TAToolsProviderInterface;
use App\TechnicalAnalysis\Application\Helper\TA;
use App\TechnicalAnalysis\Domain\Dto\AveragePriceChange;
use App\Trading\Application\Settings\SafePriceDistanceSettings;
use App\Trading\Contract\ContractBalanceProviderInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use LogicException;

/**
 * @see \App\Tests\Unit\Modules\Trading\Application\Parameters\TradingParametersProviderTest
 */
final readonly class TradingDynamicParameters implements TradingParametersProviderInterface, AppDynamicParametersProviderInterface
{
    private const float ATR_BASE_MULTIPLIER = 2;

    public function __construct(
        private AppSettingsProviderInterface $settingsProvider,

        #[AppDynamicParameterAutowiredArgument]
        private TAToolsProviderInterface $taProvider,

        #[AppDynamicParameterAutowiredArgument]
        private ContractBalanceProviderInterface $contractBalanceProvider,
    ) {
    }

    public static function riskLevel(SymbolInterface $symbol, Side $side): RiskLevel
    {
        return SettingsHelper::withAlternatives(TradingSettings::Global_RiskLevel, $symbol, $side);
    }

    public static function safePriceDistanceApplyStrategy(SymbolInterface $symbol, Side $positionSide): SafePriceAssertionStrategyEnum
    {
        $override = SettingsHelper::withAlternativesOptional(SafePriceDistanceSettings::SafePriceDistance_Apply_Strategy, $symbol, $positionSide);

        return $override ?? match (self::riskLevel($symbol, $positionSide)) {
            RiskLevel::Aggressive => SafePriceAssertionStrategyEnum::Aggressive,
            RiskLevel::Cautious => SafePriceAssertionStrategyEnum::Conservative,
            default => SafePriceAssertionStrategyEnum::Moderate,
        };
    }

    #[AppDynamicParameter(group: 'trading')]
    public function safeLiquidationPriceDelta(
        SymbolInterface $symbol,
        Side $side,
        #[AppDynamicParameterEvaluations(defaultValueProvider: DefaultValueProviderEnum::CurrentPrice)]
        float $refPrice
    ): float {
        if ($percentOverride = $this->settingsProvider->optional(SettingAccessor::exact(SafePriceDistanceSettings::SafePriceDistance_Percent, $symbol, $side))) {
            return $refPrice * ($percentOverride / 100);
        } elseif ($percentOverride = $this->settingsProvider->optional(SettingAccessor::exact(SafePriceDistanceSettings::SafePriceDistance_Percent, $symbol))) {
            return $refPrice * ($percentOverride / 100);
        }

        $k = SettingsHelper::withAlternativesOptional(SafePriceDistanceSettings::SafePriceDistance_Multiplier, $symbol, $side) ?? match ($this->riskLevel($symbol, $side)) {
            RiskLevel::Aggressive => 1,
            RiskLevel::Cautious => 3,
            default => 2,
        };

        $partOfUnrealizedToTotal = $this->contractBalanceProvider->getContractWalletBalance($symbol->associatedCoin())->unrealizedPartToTotal();
        $k *= max(1, $partOfUnrealizedToTotal / 1.8);

        $longATR = $this->taProvider->create($symbol, self::LONG_ATR_TIMEFRAME)->atr(self::LONG_ATR_PERIOD)->atr->absoluteChange;
        $fastATR = $this->taProvider->create($symbol, self::LONG_ATR_TIMEFRAME)->atr(2)->atr->absoluteChange;

        $long = self::ATR_BASE_MULTIPLIER * $longATR * $k;
        $fast = self::ATR_BASE_MULTIPLIER * $fastATR;

        return $fast > $long ? ($long + $fast) / 2 : $long;
    }

    /**
     * @todo | with refPrice?
     */
    #[AppDynamicParameter(group: 'trading')]
    public function significantPriceChange(
        SymbolInterface $symbol,
        float $passedPartOfDay = 1,
        ?float $atrBaseMultiplierOverride = null
    ): Percent {
        if ($passedPartOfDay <= 0) {
            throw new LogicException(sprintf('$passedPartOfDay cannot be less than 0 (%s provided)', $passedPartOfDay));
        }

        if ($oneDaySignificantChangePercentOverride = $this->settingsProvider->optional(SettingAccessor::exact(PriceChangeSettings::SignificantChange_OneDay_PricePercent, $symbol))) {
            return Percent::notStrict($oneDaySignificantChangePercentOverride * $passedPartOfDay);
        } else {
            $baseATR = TA::atr($symbol, self::LONG_ATR_TIMEFRAME, self::LONG_ATR_PERIOD)->atr;

            $multiplier = $atrBaseMultiplierOverride ?? $this->settingsProvider->required(
                SettingAccessor::withAlternativesAllowed(PriceChangeSettings::SignificantChange_OneDay_AtrBaseMultiplier, $symbol)
            );

            $multipliedATR = $baseATR->multiply($multiplier);

            return $multipliedATR->multiply($passedPartOfDay)->percentChange;
        }
//        $base = match (true) {$currentPrice >= 15000 => 1,$currentPrice >= 5000 => 2,$currentPrice >= 3000 => 3,$currentPrice >= 2000 => 4,$currentPrice >= 1500 => 5,$currentPrice >= 1000 => 6,$currentPrice >= 500 => 7,$currentPrice >= 100 => 8,$currentPrice >= 50 => 9,$currentPrice >= 25 => 10,$currentPrice >= 10 => 13,$currentPrice >= 5 => 14,$currentPrice >= 2.5 => 16,$currentPrice >= 1 => 17,$currentPrice >= 0.7 => 18,default => 20,};
    }

    // @todo | PredefinedStopLengthParser parameters
    #[AppDynamicParameter(group: 'trading')]
    public function standardAtrForOrdersLength(
        SymbolInterface $symbol,
        TimeFrame $timeframe = self::LONG_ATR_TIMEFRAME,
        int $period = self::ATR_PERIOD_FOR_ORDERS,
    ): AveragePriceChange {
        return $this->taProvider->create($symbol, $timeframe)->atr($period)->atr;
    }

    // @todo | PredefinedStopLengthParser parameters
    #[AppDynamicParameter(group: 'trading')]
    public function transformLengthToPricePercent(
        SymbolInterface $symbol,
        PriceDistanceSelector $length = PriceDistanceSelector::Standard,
        TimeFrame $timeframe = self::LONG_ATR_TIMEFRAME,
        int $period = self::ATR_PERIOD_FOR_ORDERS,
    ): Percent {
        $atrChangePercent = $this->standardAtrForOrdersLength($symbol, $timeframe, $period)->percentChange->value();

        $result = match ($length) {
            PriceDistanceSelector::AlmostImmideately => $atrChangePercent / 9,
            PriceDistanceSelector::VeryVeryShort => $atrChangePercent / 7,
            PriceDistanceSelector::VeryShort => $atrChangePercent / 6,
            PriceDistanceSelector::Short => $atrChangePercent / 5,
            PriceDistanceSelector::BetweenShortAndStd => $atrChangePercent / 4,
            PriceDistanceSelector::Standard => $atrChangePercent / 3.5,
            PriceDistanceSelector::BetweenLongAndStd => $atrChangePercent / 2.5,
            PriceDistanceSelector::Long => $atrChangePercent / 2,
            PriceDistanceSelector::VeryLong => $atrChangePercent,
            PriceDistanceSelector::VeryVeryLong => $atrChangePercent * 1.5,
            PriceDistanceSelector::DoubleLong => $atrChangePercent * 2,
        };

        return Percent::notStrict($result);
    }

    #[AppDynamicParameter(group: 'trading', name: 'lengths')]
    public function allPredefinedLengths(
        SymbolInterface $symbol,
        TimeFrame $timeframe = self::LONG_ATR_TIMEFRAME,
        int $period = self::ATR_PERIOD_FOR_ORDERS,
    ): array {
        $result = [];
        foreach (PriceDistanceSelector::cases() as $case) {
            $result[$case->value] = $this->transformLengthToPricePercent($symbol, $case, $timeframe, $period);
        }

        return $result;
    }

    // @todo | PredefinedStopLengthParser parameters
    #[AppDynamicParameter(group: 'trading')]
    public function oppositeBuyLength(
        SymbolInterface $symbol,
        PriceDistanceSelector $distanceSelector = PriceDistanceSelector::Standard,
        TimeFrame $timeframe = self::LONG_ATR_TIMEFRAME,
        int $period = self::ATR_PERIOD_FOR_ORDERS,
    ): Percent {
        // @todo | settings
        $multiplier = 1.2;

        $percent = $this->transformLengthToPricePercent($symbol, $distanceSelector, $timeframe, $period);

        return Percent::notStrict($percent->value() * $multiplier);
    }
}
