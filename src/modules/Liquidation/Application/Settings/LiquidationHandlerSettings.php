<?php

declare(strict_types=1);

namespace App\Liquidation\Application\Settings;

use App\Settings\Application\Attribute\SettingParametersAttribute;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use App\Settings\Domain\Enum\SettingType;

enum LiquidationHandlerSettings: string implements AppSettingInterface, AppSettingsGroupInterface
{
    #[SettingParametersAttribute(type: SettingType::Float)]
    case CriticalPartOfLiquidationDistance = 'liquidationHandlerSettings.CriticalPartOfLiquidationDistance';

    #[SettingParametersAttribute(type: SettingType::Percent)]
    case PercentOfLiquidationDistanceToAddStop = 'liquidationHandlerSettings.percentOfLiquidationDistanceToAddStop';

    #[SettingParametersAttribute(type: SettingType::Float)]
    case WarningDistancePnl = 'liquidationHandlerSettings.warningDistancePnl';

    #[SettingParametersAttribute(type: SettingType::Boolean)]
    case FixOppositeIfMain = 'liquidationHandlerSettings.fixOpposite.if.oppositeBecameMain';

    #[SettingParametersAttribute(type: SettingType::Boolean)]
    case FixOppositeEvenIfSupport = 'liquidationHandlerSettings.fixOpposite.evenIf.oppositeIsSupport';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
