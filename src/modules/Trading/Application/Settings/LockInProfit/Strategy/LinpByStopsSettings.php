<?php

declare(strict_types=1);

namespace App\Trading\Application\Settings\LockInProfit\Strategy;

use App\Settings\Application\Attribute\SettingParametersAttribute;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use App\Settings\Domain\Enum\SettingType;

enum LinpByStopsSettings: string implements AppSettingInterface, AppSettingsGroupInterface
{
    public static function category(): string
    {
        return 'trading.lockInProfit.stops';
    }

    #[SettingParametersAttribute(type: SettingType::Boolean)]
    case Enabled = 'trading.lockInProfit.stopsGridSteps.enabled';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
