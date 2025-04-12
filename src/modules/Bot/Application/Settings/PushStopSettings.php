<?php

declare(strict_types=1);

namespace App\Bot\Application\Settings;

use App\Settings\Application\Service\SettingKeyAware;

enum PushStopSettings: string implements SettingKeyAware
{
    case Cover_Loss_After_Close_By_Market = 'push.stop.cover_loss_after_close_by_market.enabled';
    case MainPositionSafeLiqDistance_After_PushSupportPositionStops = 'push.stop.mainPositionSafeLiqDistance.afterPushSupportPositionStops';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
