<?php

declare(strict_types=1);

namespace App\Connection\Application\Settings;

use App\Settings\Application\Attribute\SettingParametersAttribute;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use App\Settings\Domain\Enum\SettingType;

enum ConnectionSettings: string implements AppSettingInterface, AppSettingsGroupInterface
{
    #[SettingParametersAttribute(type: SettingType::Boolean)]
    case CheckConnectionEnabled = 'connection.check.enabled';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
