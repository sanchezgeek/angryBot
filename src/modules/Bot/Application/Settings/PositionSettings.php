<?php

declare(strict_types=1);

namespace App\Bot\Application\Settings;

use App\Settings\Application\Service\SettingKeyAware;

enum PositionSettings: string implements SettingKeyAware
{
    case Positions_OpenedPositions = 'positions.openedPositions';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
