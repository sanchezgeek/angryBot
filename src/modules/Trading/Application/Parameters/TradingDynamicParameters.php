<?php

declare(strict_types=1);

namespace App\Trading\Application\Parameters;

use App\Domain\Position\ValueObject\Side;
use App\Domain\Trading\Enum\PredefinedStopLengthSelector;
use App\Domain\Trading\Enum\TimeFrame;
use App\Domain\Value\Percent\Percent;
use App\Screener\Application\Settings\PriceChangeSettings;
use App\Settings\Application\Contract\AppDynamicParametersProviderInterface;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameter;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterAutowiredArgument;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterEvaluations;
use App\Settings\Application\DynamicParameters\DefaultValues\DefaultValueProviderEnum;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;
use App\TechnicalAnalysis\Application\Contract\TAToolsProviderInterface;
use App\TechnicalAnalysis\Domain\Dto\AveragePriceChange;
use App\Trading\Application\Settings\SafePriceDistanceSettings;
use App\Trading\Domain\Symbol\SymbolInterface;
use LogicException;

/**
 * @see \App\Tests\Unit\Modules\Trading\Application\Parameters\TradingParametersProviderTest
 */
final readonly class TradingDynamicParameters implements TradingParametersProviderInterface, AppDynamicParametersProviderInterface
{
    private const float ATR_BASE_MULTIPLIER = 2;
    public const int LONG_ATR_PERIOD = 10;
    public const TimeFrame LONG_ATR_TIMEFRAME = TimeFrame::D1;

    public function __construct(
        private AppSettingsProviderInterface $settingsProvider,

        #[AppDynamicParameterAutowiredArgument]
        private TAToolsProviderInterface $taProvider,
    ) {
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

        $k = $this->settingsProvider->required(SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Multiplier, $symbol, $side));

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
    public function significantPriceChangePercent(
        SymbolInterface $symbol,
        float $passedPartOfDay = 1
    ): Percent {
        if ($passedPartOfDay <= 0) {
            throw new LogicException(sprintf('$passedPartOfDay cannot be less than 0 (%s provided)', $passedPartOfDay));
        }

        if ($oneDaySignificantChangePercentOverride = $this->settingsProvider->optional(SettingAccessor::exact(PriceChangeSettings::SignificantDelta_OneDay_PricePercent, $symbol))) {
            return Percent::notStrict($oneDaySignificantChangePercentOverride * $passedPartOfDay);
        } else {
            $timeFrame = self::LONG_ATR_TIMEFRAME;
            $atrPeriod = self::LONG_ATR_PERIOD;

            $ta = $this->taProvider->create($symbol, $timeFrame);
            $baseATR = $ta->atr($atrPeriod)->atr;
            $multipliedATR = $baseATR->multiply(
                $this->settingsProvider->required(SettingAccessor::withAlternativesAllowed(PriceChangeSettings::SignificantDelta_OneDay_BaseMultiplier, $symbol))
            );

            return $multipliedATR->multiply($passedPartOfDay)->percentChange;
        }
//        $base = match (true) {$currentPrice >= 15000 => 1,$currentPrice >= 5000 => 2,$currentPrice >= 3000 => 3,$currentPrice >= 2000 => 4,$currentPrice >= 1500 => 5,$currentPrice >= 1000 => 6,$currentPrice >= 500 => 7,$currentPrice >= 100 => 8,$currentPrice >= 50 => 9,$currentPrice >= 25 => 10,$currentPrice >= 10 => 13,$currentPrice >= 5 => 14,$currentPrice >= 2.5 => 16,$currentPrice >= 1 => 17,$currentPrice >= 0.7 => 18,default => 20,};
    }

    // @todo | PredefinedStopLengthParser parameters
    #[AppDynamicParameter(group: 'trading')]
    public function standardAtrForOrdersLength(
        SymbolInterface $symbol,
        TimeFrame $timeframe = TimeFrame::D1,
        int $period = 4,
    ): AveragePriceChange {
        return $this->taProvider->create($symbol, $timeframe)->atr($period)->atr;
    }

    // @todo | PredefinedStopLengthParser parameters
    #[AppDynamicParameter(group: 'trading')]
    public function regularPredefinedStopLength(
        SymbolInterface $symbol,
        PredefinedStopLengthSelector $predefinedStopLength = PredefinedStopLengthSelector::Standard,
        TimeFrame $timeframe = TimeFrame::D1,
        int $period = 4,
    ): Percent {
        $atrChangePercent = $this->standardAtrForOrdersLength($symbol, $timeframe, $period)->percentChange->value();

        $result = match ($predefinedStopLength) {
            PredefinedStopLengthSelector::VeryShort => $atrChangePercent / 5,
            PredefinedStopLengthSelector::Short => $atrChangePercent / 4,
            PredefinedStopLengthSelector::ModerateShort => $atrChangePercent / 3.5,
            PredefinedStopLengthSelector::Standard => $atrChangePercent / 3,
            PredefinedStopLengthSelector::ModerateLong => $atrChangePercent / 2.5,
            PredefinedStopLengthSelector::Long => $atrChangePercent / 2,
            PredefinedStopLengthSelector::VeryLong => $atrChangePercent,
        };

        return Percent::notStrict($result);
    }

    // @todo | PredefinedStopLengthParser parameters
    #[AppDynamicParameter(group: 'trading')]
    public function regularOppositeBuyOrderLength(
        SymbolInterface $symbol,
        PredefinedStopLengthSelector $sourceStopLength = PredefinedStopLengthSelector::Standard,
        TimeFrame $timeframe = TimeFrame::D1,
        int $period = 4,
    ): Percent {
        // @todo | settings
        $multiplier = 1.2;

        $percent = $this->regularPredefinedStopLength($symbol, $sourceStopLength, $timeframe, $period);

        return Percent::notStrict($percent->value() * $multiplier);
    }
}
