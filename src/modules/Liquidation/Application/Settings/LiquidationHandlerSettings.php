<?php

declare(strict_types=1);

namespace App\Liquidation\Application\Settings;

use App\Settings\Application\Attribute\SettingParametersAttribute;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use App\Settings\Domain\Enum\SettingType;

enum LiquidationHandlerSettings: string implements AppSettingInterface, AppSettingsGroupInterface
{
    #[SettingParametersAttribute(type: SettingType::Float, nullable: true)]
    case CriticalPartOfLiquidationDistance = 'liquidationHandlerSettings.CriticalPartOfLiquidationDistance';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
