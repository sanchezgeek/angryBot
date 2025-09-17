<?php

declare(strict_types=1);

namespace App\Trading\Application\Settings\Balance;

use App\Settings\Application\Attribute\SettingParametersAttribute;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use App\Settings\Domain\Enum\SettingType;

enum TradingBalanceSettings: string implements AppSettingInterface, AppSettingsGroupInterface
{
    public static function category(): string
    {
        return 'trading.balance';
    }

    #[SettingParametersAttribute(type: SettingType::Float)]
    case RealContractBalance_Max_Ratio = 'trading.balance.realContractBalance.maxRatio';

    #[SettingParametersAttribute(type: SettingType::Float)]
    case AvailableContractBalance_Threshold = 'trading.balance.availableContractBalance.threshold';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
