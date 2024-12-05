<?php

declare(strict_types=1);

namespace App\Bot\Application\Settings;

use App\Settings\Application\Service\SettingKeyAware;

enum TradingSettings: string implements SettingKeyAware
{
    case MarketBuy_SafePriceDistance = 'trading.marketBuy.safePriceDistance';

    case Opposite_BuyOrder_PnlDistance_ForLongPosition = 'trading.opposite.BuyOrder.pnlDistance.forLongPosition';
    case Opposite_BuyOrder_PnlDistance_ForShortPosition = 'trading.opposite.BuyOrder.pnlDistance.forShortPosition';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
