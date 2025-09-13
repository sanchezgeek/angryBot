<?php

declare(strict_types=1);

namespace App\Trading\Application\Settings;

use App\Settings\Application\Attribute\SettingParametersAttribute;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use App\Settings\Domain\Enum\SettingType;

enum OpenPositionSettings: string implements AppSettingInterface, AppSettingsGroupInterface
{
    public static function category(): string
    {
        return 'trading.openPosition';
    }

    #[SettingParametersAttribute(type: SettingType::String, nullable: true)]
    case SplitToBuyOrders_DefaultGridsDefinition = 'openPosition.splitToBuyOrders.defaultGridsDefinition';

    #[SettingParametersAttribute(type: SettingType::String, nullable: true)]
    case Stops_DefaultGridDefinition = 'openPosition.stops.defaultGridsDefinition';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
