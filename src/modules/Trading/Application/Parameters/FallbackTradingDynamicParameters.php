<?php

declare(strict_types=1);

namespace App\Trading\Application\Parameters;

use App\Domain\Position\ValueObject\Side;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameter;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterEvaluations;
use App\Settings\Application\DynamicParameters\DefaultValues\DefaultValueProviderEnum;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;
use App\Trading\Application\Settings\SafePriceDistanceSettings;
use App\Trading\Domain\Symbol\SymbolInterface;

/**
 * @see \App\Tests\Unit\Modules\Trading\Application\Parameters\TradingParametersProviderTest
 */
final readonly class FallbackTradingDynamicParameters
{
    public function __construct(private AppSettingsProviderInterface $settingsProvider)
    {
    }

    #[AppDynamicParameter(group: 'trading')]
    public function safeLiquidationPriceDelta(
        SymbolInterface $symbol,
        Side $side,
        #[AppDynamicParameterEvaluations(defaultValueProvider: DefaultValueProviderEnum::CurrentPrice)]
        float $refPrice
    ): float {
        $k = $this->settingsProvider->required(SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Multiplier, $symbol, $side));

        $base = match (true) {
            $refPrice >= 10000 => $refPrice / 12,
            $refPrice >= 5000 => $refPrice / 10,
            $refPrice >= 2000 => $refPrice / 9,
            $refPrice >= 1500 => $refPrice / 8,
            $refPrice >= 1000 => $refPrice / 6,
            $refPrice >= 100 => $refPrice / 4,
            $refPrice >= 1 => $refPrice / 3,
            $refPrice >= 0.1 => $refPrice / 2.5,
            $refPrice >= 0.05 => $refPrice / 2,
            $refPrice >= 0.03 => $refPrice,
            default => $refPrice * 1.4,
            // default => $closingPosition->entryPrice()->deltaWith($ticker->markPrice) * 2
        };

        return $base * $k;
    }
}
