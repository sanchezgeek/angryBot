<?php

declare(strict_types=1);

namespace App\Trading\Application\Parameters;

use App\Domain\Candle\Enum\CandleIntervalEnum;
use App\Domain\Position\ValueObject\Side;
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
use App\Trading\Application\Settings\SafePriceDistanceSettings;
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

        $longATR = $this->taProvider->create($symbol, CandleIntervalEnum::D1)->atr(7)->atr->absoluteChange;
        $fastATR = $this->taProvider->create($symbol, CandleIntervalEnum::D1)->atr(2)->atr->absoluteChange;

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
            $timeFrame = CandleIntervalEnum::D1;
            $atrPeriod = 7;

            $ta = $this->taProvider->create($symbol, $timeFrame);
            $baseATR = $ta->atr($atrPeriod)->atr;
            $multipliedATR = $baseATR->multiply(
                $this->settingsProvider->required(SettingAccessor::withAlternativesAllowed(PriceChangeSettings::SignificantDelta_OneDay_BaseMultiplier, $symbol))
            );

            return $multipliedATR->multiply($passedPartOfDay)->percentChange;
        }
//        $base = match (true) {$currentPrice >= 15000 => 1,$currentPrice >= 5000 => 2,$currentPrice >= 3000 => 3,$currentPrice >= 2000 => 4,$currentPrice >= 1500 => 5,$currentPrice >= 1000 => 6,$currentPrice >= 500 => 7,$currentPrice >= 100 => 8,$currentPrice >= 50 => 9,$currentPrice >= 25 => 10,$currentPrice >= 10 => 13,$currentPrice >= 5 => 14,$currentPrice >= 2.5 => 16,$currentPrice >= 1 => 17,$currentPrice >= 0.7 => 18,default => 20,};
    }
}
