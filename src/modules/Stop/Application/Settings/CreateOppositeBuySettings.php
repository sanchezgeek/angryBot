<?php

declare(strict_types=1);

namespace App\Stop\Application\Settings;

use App\Settings\Application\Attribute\SettingParametersAttribute;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use App\Settings\Domain\Enum\SettingType;

enum CreateOppositeBuySettings: string implements AppSettingInterface, AppSettingsGroupInterface
{
    #[SettingParametersAttribute(type: SettingType::Boolean)]
    case Martingale_Enabled = 'stop.createOpposite.martingale.enabled';

    #[SettingParametersAttribute(type: SettingType::Boolean)]
    case Add_Force_Flag_Enabled = 'stop.createOpposite.addForceFlag.enabled';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
