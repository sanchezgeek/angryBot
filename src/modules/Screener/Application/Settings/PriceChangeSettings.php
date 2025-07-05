<?php

declare(strict_types=1);

namespace App\Screener\Application\Settings;

use App\Settings\Application\Attribute\SettingParametersAttribute;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use App\Settings\Domain\Enum\SettingType;

enum PriceChangeSettings: string implements AppSettingInterface, AppSettingsGroupInterface
{
    #[SettingParametersAttribute(type: SettingType::Float, nullable: true)]
    case SignificantChange_OneDay_PricePercent = 'priceChange.significantDelta.oneDay.pricePercent';

    #[SettingParametersAttribute(type: SettingType::Float, nullable: true)]
    case SignificantChange_OneDay_AtrBaseMultiplier = 'priceChange.significantDelta.oneDay.atr.baseMultiplier';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
