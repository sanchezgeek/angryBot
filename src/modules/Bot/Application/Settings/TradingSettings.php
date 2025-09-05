<?php

declare(strict_types=1);

namespace App\Bot\Application\Settings;

use App\Domain\Trading\Enum\TradingStyle;
use App\Settings\Application\Attribute\SettingParametersAttribute;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use App\Settings\Domain\Enum\SettingType;

enum TradingSettings: string implements AppSettingInterface, AppSettingsGroupInterface
{
    #[SettingParametersAttribute(type: SettingType::Enum, enumClass: TradingStyle::class)]
    case Global_Trading_Style = 'trading.global.tradingStyle';

    # Other
    #[SettingParametersAttribute(type: SettingType::Boolean)]
    case TakeProfit_InCaseOf_Insufficient_Balance_Enabled = 'trading.takeProfitWhenBalanceIsInsufficient.enabled';

    #[SettingParametersAttribute(type: SettingType::Float)]
    case TakeProfit_InCaseOf_Insufficient_Balance_After_Position_Pnl_Percent = 'trading.takeProfitWhenBalanceIsInsufficient.afterProfitPercent';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
