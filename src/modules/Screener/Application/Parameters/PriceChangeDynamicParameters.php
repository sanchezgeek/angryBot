<?php

declare(strict_types=1);

namespace App\Screener\Application\Parameters;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Value\Percent\Percent;
use App\Screener\Application\Settings\PriceChangeSettings;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameter;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterEvaluations;
use App\Settings\Application\DynamicParameters\DefaultValues\DefaultValueProviderEnum;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;

/**
 * @see \App\Tests\Unit\Modules\Screener\Application\Parameters\PriceChangeDynamicParametersTest
 */
final readonly class PriceChangeDynamicParameters
{
    public function __construct(private AppSettingsProviderInterface $settingsProvider)
    {
    }

    #[AppDynamicParameter(group: 'priceChange')]
    public function alarmDeltaPercent(
        #[AppDynamicParameterEvaluations(defaultValueProvider: DefaultValueProviderEnum::CurrentPrice)]
        float $currentPrice,

        ?Symbol $symbol = null,
    ): Percent {
        if ($percentOverride = $this->settingsProvider->optional(SettingAccessor::exact(PriceChangeSettings::Alarm_Pnl_Percent, $symbol))) {
            return Percent::notStrict($percentOverride);
        }

        $percent = match (true) {
            $currentPrice >= 15000 => 1,
            $currentPrice >= 5000 => 2,
            $currentPrice >= 3000 => 3,
            $currentPrice >= 2000 => 4,
            $currentPrice >= 1500 => 5,
            $currentPrice >= 1000 => 6,
            $currentPrice >= 500 => 7,
            $currentPrice >= 100 => 8,
            $currentPrice >= 50 => 9,
            $currentPrice >= 25 => 10,
            $currentPrice >= 10 => 13,
            $currentPrice >= 5 => 14,
            $currentPrice >= 2.5 => 16,
            $currentPrice >= 1 => 17,
            $currentPrice >= 0.7 => 18,
            default => 20,
        };

        return Percent::notStrict($percent);
    }

    public function alarmDelta(
        #[AppDynamicParameterEvaluations(defaultValueProvider: DefaultValueProviderEnum::CurrentPrice)]
        float $currentPrice,

        ?Symbol $symbol = null,
    ): float {
        $percent = $this->alarmDeltaPercent($currentPrice, $symbol);

        return $percent->of($currentPrice);
    }
}
