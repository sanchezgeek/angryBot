<?php

declare(strict_types=1);

namespace App\Trading\Application\Settings;

use App\Liquidation\Domain\Assert\SafePriceAssertionStrategyEnum;
use App\Settings\Application\Attribute\SettingParametersAttribute;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use App\Settings\Domain\Enum\SettingType;

enum LockInProfitSettings: string implements AppSettingInterface, AppSettingsGroupInterface
{
    #[SettingParametersAttribute(type: SettingType::Boolean)]
    case Enabled = 'lockInProfit.enabled';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
