<?php

declare(strict_types=1);

namespace App\Alarm\Application\Settings;

use App\Settings\Application\Attribute\SettingParametersAttribute;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use App\Settings\Domain\Enum\SettingType;

enum AlarmSettings: string implements AppSettingInterface, AppSettingsGroupInterface
{
    public static function category(): string
    {
        return 'alarm';
    }

    #[SettingParametersAttribute(type: SettingType::Integer)]
    case PriceAlarm_SoundsCount = 'alarm.priceAlarm.soundsCount';

    #[SettingParametersAttribute(type: SettingType::Boolean)]
    case AlarmOnLossEnabled = 'alarm.loss.enabled';

    #[SettingParametersAttribute(type: SettingType::Boolean)]
    case AlarmOnProfitEnabled = 'alarm.profit.enabled';

    #[SettingParametersAttribute(type: SettingType::Integer)]
    case AlarmOnProfitPnlPercent = 'alarm.profit.pnlPercent';

    #[SettingParametersAttribute]
    case AlarmOnContractAvailableBalanceGreaterThan = 'alarm.balance.greater';
    #[SettingParametersAttribute]
    case AlarmOnContractAvailableBalanceLessThan = 'alarm.balance.less';

    #[SettingParametersAttribute]
    case AlarmOnTotalBalanceGreaterThan = 'alarm.balance.total.greater';
    #[SettingParametersAttribute]
    case AlarmOnTotalBalanceLessThan = 'alarm.balance.total.less';

    #[SettingParametersAttribute(type: SettingType::Float)]
    case PassedPart_Of_LiquidationDistance = 'alarm.liquidationDistance.passedPart.allowed';

    #[SettingParametersAttribute(type: SettingType::Float)]
    case PassedPart_Of_LiquidationDistance_Threshold_From_Allowed = 'alarm.liquidationDistance.passedPart.thresholdFromAllowed';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
