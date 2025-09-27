<?php

declare(strict_types=1);

namespace App\Liquidation\Application\Settings;

use App\Settings\Application\Attribute\SettingParametersAttribute;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use App\Settings\Domain\Enum\SettingType;

/**
 * @see root_liquidation_settings.yaml
 */
enum LiquidationHandlerSettings: string implements AppSettingInterface, AppSettingsGroupInterface
{
    public static function category(): string
    {
        return 'liquidation.handler';
    }

    #[SettingParametersAttribute(type: SettingType::Float)]
    case Default_Transfer_Amount = 'liquidationHandlerSettings.transferFromSpot.defaultAmount';

    #[SettingParametersAttribute(type: SettingType::Integer)]
    case LastPriceCrossingThresholdDefaultCacheTtl = 'liquidationHandlerSettings.lastPriceCrossingThresholdDefaultCacheTtl';

    #[SettingParametersAttribute(type: SettingType::Float)]
    case CriticalPartOfLiquidationDistance = 'liquidationHandlerSettings.CriticalPartOfLiquidationDistance';

    #[SettingParametersAttribute(type: SettingType::Percent)]
    case PercentOfLiquidationDistanceToAddStop = 'liquidationHandlerSettings.percentOfLiquidationDistanceToAddStop';

    #[SettingParametersAttribute(type: SettingType::Float)]
    case CriticalDistancePnl = 'liquidationHandlerSettings.criticalDistancePnl';

    #[SettingParametersAttribute(type: SettingType::Float)]
    case AcceptableStoppedPartOverride = 'liquidationHandlerSettings.acceptableStoppedPartFallback';

    #[SettingParametersAttribute(type: SettingType::Percent)]
    case ActualStopsRangeFromAdditionalStop = 'liquidationHandlerSettings.actualStopsRangeFromAdditionalStops';

    #[SettingParametersAttribute(type: SettingType::Boolean)]
    case FixOppositeIfMain = 'liquidationHandlerSettings.fixOpposite.if.oppositeBecameMain';

    #[SettingParametersAttribute(type: SettingType::Boolean)]
    case FixOppositeEvenIfSupport = 'liquidationHandlerSettings.fixOpposite.evenIf.oppositeIsSupport';

    #[SettingParametersAttribute(type: SettingType::Boolean)]
    case AddOppositeBuyOrdersAfterStop = 'liquidationHandlerSettings.afterStop.addOppositeBuyOrders';

    public function getSettingKey(): string
    {
        return $this->value;
    }
}
