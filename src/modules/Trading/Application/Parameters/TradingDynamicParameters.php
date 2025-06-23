<?php

declare(strict_types=1);

namespace App\Trading\Application\Parameters;

use App\Domain\Candle\Enum\CandleIntervalEnum;
use App\Domain\Position\ValueObject\Side;
use App\Settings\Application\Contract\AppDynamicParametersProviderInterface;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameter;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterAutowiredArgument;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterEvaluations;
use App\Settings\Application\DynamicParameters\DefaultValues\DefaultValueProviderEnum;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;
use App\TechnicalAnalysis\Application\Contract\TechnicalAnalysisToolsFactoryInterface;
use App\Trading\Application\Settings\SafePriceDistanceSettings;
use App\Trading\Domain\Symbol\SymbolInterface;

/**
 * @see \App\Tests\Unit\Modules\Trading\Application\Parameters\TradingParametersProviderTest
 */
final readonly class TradingDynamicParameters implements TradingParametersProviderInterface, AppDynamicParametersProviderInterface
{
    private const float LONG_ATR_BASE_MULTIPLIER = 1.5;

    public function __construct(
        private AppSettingsProviderInterface $settingsProvider,

        #[AppDynamicParameterAutowiredArgument]
        private TechnicalAnalysisToolsFactoryInterface $taProvider,
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

        $long = self::LONG_ATR_BASE_MULTIPLIER * $k * $this->taProvider->create($symbol, CandleIntervalEnum::D1)->atr(7)->atr->absoluteChange;

        $fast = $this->taProvider->create($symbol, CandleIntervalEnum::D1)->atr(2)->atr->absoluteChange;

        return $fast > $long ? ($long + $fast) / 2 : $long;
    }
}
