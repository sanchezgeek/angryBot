<?php

declare(strict_types=1);

namespace App\Stop\Application\Settings;

use App\Settings\Application\Attribute\SettingParametersAttribute;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use App\Settings\Domain\Enum\SettingType;

enum MovePositionStopsSettings: string implements AppSettingInterface, AppSettingsGroupInterface
{
    public static function category(): string
    {
        return 'stop';
    }

    #[SettingParametersAttribute(type: SettingType::Float)]
    case MoveToBreakeven_After_PositionPnlPercent = 'stop.movePositionStops.moveToBreakeven.after.positionPnlPercent';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
