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
    case SignificantDelta_OneDay_PricePercent = 'priceChange.significantDelta.oneDay.pricePercent';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
