<?php

declare(strict_types=1);

namespace App\Screener\Application\Parameters;

use App\Domain\Candle\Enum\CandleIntervalEnum;
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
use App\Trading\Domain\Symbol\SymbolInterface;
use LogicException;

/**
 * @see \App\Tests\Unit\Modules\Screener\Application\Parameters\PriceChangeDynamicParametersTest
 */
final readonly class PriceChangeDynamicParameters implements AppDynamicParametersProviderInterface
{
    const int ATR_DEFAULT_INTERVALS_COUNT = 7;

    #[AppDynamicParameter(group: 'priceChange')]
    public function significantPriceDelta(
        #[AppDynamicParameterEvaluations(defaultValueProvider: DefaultValueProviderEnum::CurrentPrice)]
        float $refPrice,
        float $passedPartOfOneDay,
        ?SymbolInterface $symbol = null,
    ): float {
        return $this->significantPricePercent($refPrice, $passedPartOfOneDay, $symbol)->of($refPrice);
    }

    #[AppDynamicParameter(group: 'priceChange')]
    public function significantPricePercent(
        #[AppDynamicParameterEvaluations(defaultValueProvider: DefaultValueProviderEnum::CurrentPrice)]
        float $refPrice,
        float $passedPartOfOneDay,
        ?SymbolInterface $symbol = null,
    ): Percent {
        if ($passedPartOfOneDay <= 0) {
            throw new LogicException(sprintf('$passedPartOfOneDay cannot be less than 0 (%s provided)', $passedPartOfOneDay));
        }

        $base = $this->oneDaySignificantPricePercent($refPrice, $symbol);

        return Percent::notStrict($base * $passedPartOfOneDay);
    }

    private function oneDaySignificantPricePercent(float $currentPrice, ?SymbolInterface $symbol = null): float
    {
        if ($percentOverride = $this->settingsProvider->optional(SettingAccessor::exact(PriceChangeSettings::SignificantDelta_OneDay_PricePercent, $symbol))) {
            return $percentOverride;
        }

//        $base = match (true) {
//            $currentPrice >= 15000 => 1,
//            $currentPrice >= 5000 => 2,
//            $currentPrice >= 3000 => 3,
//            $currentPrice >= 2000 => 4,
//            $currentPrice >= 1500 => 5,
//            $currentPrice >= 1000 => 6,
//            $currentPrice >= 500 => 7,
//            $currentPrice >= 100 => 8,
//            $currentPrice >= 50 => 9,
//            $currentPrice >= 25 => 10,
//            $currentPrice >= 10 => 13,
//            $currentPrice >= 5 => 14,
//            $currentPrice >= 2.5 => 16,
//            $currentPrice >= 1 => 17,
//            $currentPrice >= 0.7 => 18,
//            default => 20,
//        };

        $multiplier = $this->settingsProvider->required(SettingAccessor::withAlternativesAllowed(PriceChangeSettings::SignificantDelta_OneDay_BaseMultiplier, $symbol));
        $ta = $this->taToolsProvider->create($symbol)->withInterval(CandleIntervalEnum::D1);

        return $ta->atr(self::ATR_DEFAULT_INTERVALS_COUNT)->atr->multiply($multiplier)->percentChange->value();
    }

    public function __construct(
        private AppSettingsProviderInterface $settingsProvider,

        #[AppDynamicParameterAutowiredArgument]
        private TAToolsProviderInterface $taToolsProvider,
    ) {
    }
}
