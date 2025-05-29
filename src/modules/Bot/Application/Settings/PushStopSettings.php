<?php

declare(strict_types=1);

namespace App\Bot\Application\Settings;

use App\Bot\Application\Settings\Enum\PriceRangeLeadingToUseMarkPriceOptions;
use App\Settings\Application\Attribute\SettingParametersAttribute;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use App\Settings\Domain\Enum\SettingType;

enum PushStopSettings: string implements AppSettingInterface, AppSettingsGroupInterface
{
    #[SettingParametersAttribute(type: SettingType::Boolean)]
    case Cover_Loss_After_Close_By_Market = 'push.stop.cover_loss_after_close_by_market.enabled';

    #[SettingParametersAttribute(type: SettingType::Enum, enumClass: PriceRangeLeadingToUseMarkPriceOptions::class)]
    case WhichRangeToUse_While_ChooseMarkPrice_AsTriggerPrice = 'push.stop.whichRangeToUse.while.chooseMarkPrice.asTriggerPrice';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
