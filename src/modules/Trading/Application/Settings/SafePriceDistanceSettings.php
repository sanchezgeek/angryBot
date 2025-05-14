<?php

declare(strict_types=1);

namespace App\Trading\Application\Settings;

use App\Liquidation\Domain\Assert\SafePriceAssertionStrategyEnum;
use App\Settings\Application\Attribute\SettingParametersAttribute;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use App\Settings\Domain\Enum\SettingType;

enum SafePriceDistanceSettings: string implements AppSettingInterface, AppSettingsGroupInterface
{
    #[SettingParametersAttribute(type: SettingType::Float, nullable: true)]
    case SafePriceDistance_Percent = 'safePriceDistance.percent';

    #[SettingParametersAttribute(type: SettingType::Float)]
    case SafePriceDistance_Multiplier = 'safePriceDistance.baseMultiplier';

    #[SettingParametersAttribute(type: SettingType::Enum, enumClass: SafePriceAssertionStrategyEnum::class)]
    case SafePriceDistance_Apply_Strategy = 'safePriceDistance.applyStrategy';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
