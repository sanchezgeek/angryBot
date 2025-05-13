<?php

declare(strict_types=1);

namespace App\Stop\Application\Settings;

use App\Settings\Application\Attribute\SettingParametersAttribute;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use App\Settings\Domain\Enum\SettingType;

enum FixOppositePositionSettings: string implements AppSettingInterface, AppSettingsGroupInterface
{
    #[SettingParametersAttribute(type: SettingType::Boolean)]
    case FixOppositePosition_Enabled = 'stop.afterStop.fixOppositePosition.enabled';

    #[SettingParametersAttribute(type: SettingType::Percent)]
    case FixOppositePosition_If_OppositePositionPnl_GreaterThan = 'stop.afterStop.fixOppositePosition.applyOnlyIf.oppositePositionPnl.greaterThan';

    #[SettingParametersAttribute(type: SettingType::Percent)]
    case FixOppositePosition_supplyStopPnlDistance = 'stop.afterStop.fixOppositePosition.supplyStopPnlDistance';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
