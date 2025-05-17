<?php

declare(strict_types=1);

namespace App\Profiling\Application\Settings;

use App\Settings\Application\Attribute\SettingParametersAttribute;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use App\Settings\Domain\Enum\SettingType;

enum ProfilingSettings: string implements AppSettingInterface, AppSettingsGroupInterface
{
    #[SettingParametersAttribute(type: SettingType::Boolean, nullable: true)]
    case ProfilingEnabled = 'profiling.enabled';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
