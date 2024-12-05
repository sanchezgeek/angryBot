<?php

declare(strict_types=1);

namespace App\Bot\Application\Settings;

use App\Settings\Application\Service\SettingKeyAware;

enum TradingSettings: string implements SettingKeyAware
{
    case MarketBuy_SafePriceDistance = 'trading.marketBuy.safePriceDistance';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
