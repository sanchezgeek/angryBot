<?php

declare(strict_types=1);

namespace App\Screener\Application\Settings;

use App\Settings\Application\Attribute\SettingParametersAttribute;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use App\Settings\Domain\Enum\SettingType;

enum ScreenerEnabledHandlersSettings: string implements AppSettingInterface, AppSettingsGroupInterface
{
    #[SettingParametersAttribute(type: SettingType::Boolean)]
    case SignificantPriceChange_Screener_Enabled = 'screener.significantPriceChange.enabled';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
