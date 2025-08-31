<?php

declare(strict_types=1);

namespace App\Trading\Application\Settings;

use App\Domain\Trading\Enum\PriceDistanceSelector;
use App\Settings\Application\Attribute\SettingParametersAttribute;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use App\Settings\Domain\Enum\SettingType;

enum CoverLossSettings: string implements AppSettingInterface, AppSettingsGroupInterface
{
    #[SettingParametersAttribute(type: SettingType::Boolean)]
    case Cover_Loss_Enabled = 'trading.coverLosses.enabled';

    #[SettingParametersAttribute(type: SettingType::Boolean)]
    case Cover_Loss_By_SpotBalance = 'trading.coverLosses.bySpotBalance.enabled';

    #[SettingParametersAttribute(type: SettingType::Enum, enumClass: PriceDistanceSelector::class)]
    case Cover_Loss_By_OtherSymbols_AdditionalStop_Distance = 'trading.coverLosses.otherSymbols.additionalStop.distance';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
