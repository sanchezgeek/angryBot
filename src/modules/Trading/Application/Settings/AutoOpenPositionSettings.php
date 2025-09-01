<?php

declare(strict_types=1);

namespace App\Trading\Application\Settings;

use App\Settings\Application\Attribute\SettingParametersAttribute;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use App\Settings\Domain\Enum\SettingType;

enum AutoOpenPositionSettings: string implements AppSettingInterface, AppSettingsGroupInterface
{
    #[SettingParametersAttribute(type: SettingType::Boolean)]
    case Notifications_Enabled = 'autoOpen.notifications.enabled';

    #[SettingParametersAttribute(type: SettingType::Boolean)]
    case AutoOpen_Enabled = 'autoOpen.enabled';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
