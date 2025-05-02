<?php

declare(strict_types=1);

namespace App\Bot\Application\Settings;

use App\Settings\Application\Contract\SettingKeyAware;

enum TradingSettings: string implements SettingKeyAware
{
    case MarketBuy_SafePriceDistance = 'trading.marketBuy.safePriceDistance';

    # Opposite BuyOrders (after SL executed)
    case Opposite_BuyOrder_PnlDistance_ForLongPosition = 'trading.opposite.BuyOrder.pnlDistance.forLongPosition';
    case Opposite_BuyOrder_PnlDistance_ForShortPosition = 'trading.opposite.BuyOrder.pnlDistance.forShortPosition';

    case Opposite_BuyOrder_PnlDistance_ForLongPosition_AltCoin = 'trading.opposite.BuyOrder.altCoin.pnlDistance.forLongPosition';
    case Opposite_BuyOrder_PnlDistance_ForShortPosition_AltCoin = 'trading.opposite.BuyOrder.altCoin.pnlDistance.forShortPosition';

    # Other
    case TakeProfit_InCaseOf_Insufficient_Balance_Enabled = 'trading.takeProfitWhenBalanceIsInsufficient.enabled';
    case TakeProfit_InCaseOf_Insufficient_Balance_After_Position_Pnl_Percent = 'trading.takeProfitWhenBalanceIsInsufficient.afterProfitPercent';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
