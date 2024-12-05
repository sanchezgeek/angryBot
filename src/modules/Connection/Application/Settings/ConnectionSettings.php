<?php

declare(strict_types=1);

namespace App\Connection\Application\Settings;

use App\Settings\Application\Service\SettingKeyAware;

enum ConnectionSettings: string implements SettingKeyAware
{
    case CheckConnectionEnabled = 'connection.check.enabled';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
