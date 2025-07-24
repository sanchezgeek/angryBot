<?php

declare(strict_types=1);

namespace App\Liquidation\Application\Settings;

use App\Settings\Application\Attribute\SettingParametersAttribute;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use App\Settings\Domain\Enum\SettingType;

enum WarningDistanceSettings: string implements AppSettingInterface, AppSettingsGroupInterface
{
    #[SettingParametersAttribute(type: SettingType::Float)]
    case WarningDistancePnl = 'liquidationHandlerSettings.warningDistancePnl';

    #[SettingParametersAttribute(type: SettingType::Float)]
    case WarningDistancePnlPercentMax = 'liquidationHandlerSettings.warningDistancePnlPercent.max';

    #[SettingParametersAttribute(type: SettingType::Integer)]
    case WarningDistancePnlPercentAtrPeriod = 'liquidationHandlerSettings.warningDistancePnlPercent.atr.period';

    #[SettingParametersAttribute(type: SettingType::Float)]
    case WarningDistancePnlPercentAtrMultiplier = 'liquidationHandlerSettings.warningDistancePnlPercent.atr.multiplier';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
