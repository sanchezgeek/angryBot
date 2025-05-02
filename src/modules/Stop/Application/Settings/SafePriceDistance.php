<?php

declare(strict_types=1);

namespace App\Stop\Application\Settings;

use App\Settings\Application\Contract\SettingKeyAware;

enum SafePriceDistance: string implements SettingKeyAware
{
    case SafePriceDistance_Percent = 'safePriceDistance.percent';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
