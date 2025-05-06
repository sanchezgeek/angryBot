<?php

declare(strict_types=1);

namespace App\Trading\Application\Settings;

use App\Settings\Application\Attribute\SettingParametersAttribute;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use App\Settings\Domain\Enum\SettingType;

enum SafePriceDistanceSettings: string implements AppSettingInterface, AppSettingsGroupInterface
{
    #[SettingParametersAttribute(type: SettingType::Float, nullable: true)]
    case SafePriceDistance_Percent = 'safePriceDistance.percent';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
