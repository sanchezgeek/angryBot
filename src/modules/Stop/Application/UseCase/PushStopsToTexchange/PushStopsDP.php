<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\PushStopsToTexchange;

use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\DynamicParameters\LiquidationDynamicParametersFactoryInterface;
use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\DynamicParameters\LiquidationDynamicParametersInterface;
use App\Bot\Application\Settings\Enum\PriceRangeLeadingToUseMarkPriceOptions;
use App\Bot\Application\Settings\PushStopSettingsWrapper;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Domain\Order\Parameter\TriggerBy;
use App\Settings\Application\Contract\AppDynamicParametersProviderInterface;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameter;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterAutowiredArgument;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterEvaluations;
use App\Settings\Application\DynamicParameters\DefaultValues\DefaultValueProviderEnum;

/**
 * aka "DynamicParameters"
 */
final class PushStopsDP implements AppDynamicParametersProviderInterface
{
    private ?LiquidationDynamicParametersInterface $liquidationDP = null;

    public function __construct(
        #[AppDynamicParameterAutowiredArgument]
        private readonly LiquidationDynamicParametersFactoryInterface $liquidationDynamicParametersFactory,

        #[AppDynamicParameterEvaluations(defaultValueProvider: DefaultValueProviderEnum::CurrentPositionState, skipUserInput: true)]
        private readonly Position $position,

        #[AppDynamicParameterEvaluations(defaultValueProvider: DefaultValueProviderEnum::CurrentTicker, skipUserInput: true)]
        private readonly Ticker $ticker,
    ) {
    }

    #[AppDynamicParameter(group: 'push-stops', name: 'price-to-use')]
    public function priceToUseWhenPushStopsToExchange(): TriggerBy
    {
        $position = $this->position;
        $ticker = $this->ticker;

        $liquidationDP = $this->getLiquidationDP();

        $distanceToUseMarkPrice = PushStopSettingsWrapper::rangeToUseWhileChooseMarkPrice($position) === PriceRangeLeadingToUseMarkPriceOptions::WarningRange
            ? $liquidationDP->warningDistanceRaw()
            : $liquidationDP->criticalDistance();

        $distanceToUseMarkPrice *= 2;

        $distanceWithLiquidation = $position->priceDistanceWithLiquidation($ticker);

        return $distanceWithLiquidation <= $distanceToUseMarkPrice ? TriggerBy::MarkPrice : TriggerBy::IndexPrice;
    }

    #[AppDynamicParameter(group: 'push-stops', name: 'critical-distance')]
    public function criticalDistance(): float
    {
        return $this->getLiquidationDP()->criticalDistance();
    }

    private function getLiquidationDP(): LiquidationDynamicParametersInterface
    {
        if ($this->liquidationDP !== null) {
            return $this->liquidationDP;
        }

        return $this->liquidationDP = $this->liquidationDynamicParametersFactory->fakeWithoutHandledMessage($this->position, $this->ticker);
    }
}
