<?php

declare(strict_types=1);

namespace App\Trading\Application\Settings\LockInProfit\Strategy;

use App\Settings\Application\Attribute\SettingParametersAttribute;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use App\Settings\Domain\Enum\SettingType;

enum LinpByFixationsSettings: string implements AppSettingInterface, AppSettingsGroupInterface
{
    public static function category(): string
    {
        return 'trading.lockInProfit.fixations';
    }

    #[SettingParametersAttribute(type: SettingType::Boolean)]
    case Periodical_Enabled = 'lockInProfit.periodicalFixations.enabled';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
