<?php

declare(strict_types=1);

namespace App\Screener\Application\Settings;

use App\Settings\Application\Attribute\SettingParametersAttribute;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use App\Settings\Domain\Enum\SettingType;

enum ScreenerNotificationsSettings: string implements AppSettingInterface, AppSettingsGroupInterface
{
    #[SettingParametersAttribute(type: SettingType::Boolean)]
    case SignificantPriceChange_Notifications_Enabled = 'screener.significantPriceChange.notifications.enabled';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
