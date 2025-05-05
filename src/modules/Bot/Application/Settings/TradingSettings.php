<?php

declare(strict_types=1);

namespace App\Bot\Application\Settings;

use App\Settings\Application\Attribute\SettingParametersAttribute;
use App\Settings\Application\Contract\SettingKeyAware;
use App\Settings\Domain\Enum\SettingType;

enum TradingSettings: string implements SettingKeyAware
{
    # Opposite BuyOrders (after SL executed)
    #[SettingParametersAttribute(type: SettingType::Percent)]
    case Opposite_BuyOrder_PnlDistance_ForLongPosition = 'trading.opposite.BuyOrder.pnlDistance.forLongPosition';
    #[SettingParametersAttribute(type: SettingType::Percent)]
    case Opposite_BuyOrder_PnlDistance_ForShortPosition = 'trading.opposite.BuyOrder.pnlDistance.forShortPosition';

    #[SettingParametersAttribute(type: SettingType::Percent)]
    case Opposite_BuyOrder_PnlDistance_ForLongPosition_AltCoin = 'trading.opposite.BuyOrder.altCoin.pnlDistance.forLongPosition';
    #[SettingParametersAttribute(type: SettingType::Percent)]
    case Opposite_BuyOrder_PnlDistance_ForShortPosition_AltCoin = 'trading.opposite.BuyOrder.altCoin.pnlDistance.forShortPosition';

    # Other
    #[SettingParametersAttribute(type: SettingType::Boolean)]
    case TakeProfit_InCaseOf_Insufficient_Balance_Enabled = 'trading.takeProfitWhenBalanceIsInsufficient.enabled';

    #[SettingParametersAttribute(type: SettingType::Float)]
    case TakeProfit_InCaseOf_Insufficient_Balance_After_Position_Pnl_Percent = 'trading.takeProfitWhenBalanceIsInsufficient.afterProfitPercent';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
