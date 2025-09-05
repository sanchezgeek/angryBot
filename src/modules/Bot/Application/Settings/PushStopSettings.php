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
    public static function category(): string
    {
        return 'stop.push';
    }

    #[SettingParametersAttribute(type: SettingType::Enum, enumClass: PriceRangeLeadingToUseMarkPriceOptions::class)]
    case WhichRangeToUse_While_ChooseMarkPrice_AsTriggerPrice = 'push.stop.whichRangeToUse.while.chooseMarkPrice.asTriggerPrice';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
