<?php

declare(strict_types=1);

namespace App\Alarm\Application\Settings;

use App\Settings\Application\Attribute\SettingParametersAttribute;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use App\Settings\Domain\Enum\SettingType;

enum AlarmSettings: string implements AppSettingInterface, AppSettingsGroupInterface
{
    #[SettingParametersAttribute(type: SettingType::Boolean)]
    case AlarmOnLossEnabled = 'alarm.loss.enabled';

    #[SettingParametersAttribute(type: SettingType::Boolean)]
    case AlarmOnProfitEnabled = 'alarm.profit.enabled';

    #[SettingParametersAttribute(type: SettingType::Integer)]
    case AlarmOnProfitPnlPercent = 'alarm.profit.pnlPercent';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
