<?php

declare(strict_types=1);

namespace App\Buy\Application\Settings;

use App\Settings\Application\Attribute\SettingParametersAttribute;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use App\Settings\Domain\Enum\SettingType;

enum PushBuyOrderSettings: string implements AppSettingInterface, AppSettingsGroupInterface
{
    #[SettingParametersAttribute(type: SettingType::Boolean)]
    case UseSpot_Enabled = 'push.BuyOrder.useSpot.enabled';

    #[SettingParametersAttribute(type: SettingType::Boolean)]
    case Checks_lastPriceOverIndexPriceCheckEnabled = 'push.BuyOrder.checks.isLastPriceOverIndexPrice.enabled';

    #[SettingParametersAttribute(type: SettingType::Boolean)]
    case Checks_ignoreBuyBasedOnTotalPositionLeverageEnabled = 'push.BuyOrder.checks.ignoreBuyBasedOnTotalPositionLeverage.enabled';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
