<?php

declare(strict_types=1);

namespace App\Bot\Application\Settings;

use App\Settings\Application\Attribute\SettingParametersAttribute;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use App\Settings\Domain\Enum\SettingType;

enum PushStopSettings: string implements AppSettingInterface, AppSettingsGroupInterface
{
    #[SettingParametersAttribute(type: SettingType::Boolean)]
    case Cover_Loss_After_Close_By_Market = 'push.stop.cover_loss_after_close_by_market.enabled';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
