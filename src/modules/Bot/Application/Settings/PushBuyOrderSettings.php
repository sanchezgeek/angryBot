<?php

declare(strict_types=1);

namespace App\Bot\Application\Settings;

use App\Settings\Application\Contract\SettingKeyAware;

enum PushBuyOrderSettings: string implements SettingKeyAware
{
    case Checks_lastPriceOverIndexPriceCheckEnabled = 'push.BuyOrder.checks.isLastPriceOverIndexPrice.enabled';
    case Checks_ignoreBuyBasedOnTotalPositionLeverageEnabled = 'push.BuyOrder.checks.ignoreBuyBasedOnTotalPositionLeverage.enabled';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
