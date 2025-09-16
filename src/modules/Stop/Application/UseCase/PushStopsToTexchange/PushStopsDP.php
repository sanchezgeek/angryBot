<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\PushStopsToTexchange;

use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\DynamicParameters\LiquidationDynamicParametersFactoryInterface;
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
final readonly class PushStopsDP implements AppDynamicParametersProviderInterface
{
    public function __construct(
        #[AppDynamicParameterAutowiredArgument]
        private LiquidationDynamicParametersFactoryInterface $liquidationDynamicParametersFactory,

        #[AppDynamicParameterEvaluations(defaultValueProvider: DefaultValueProviderEnum::CurrentPositionState, skipUserInput: true)]
        private Position $position,

        #[AppDynamicParameterEvaluations(defaultValueProvider: DefaultValueProviderEnum::CurrentTicker, skipUserInput: true)]
        private Ticker $ticker,
    ) {
    }

    #[AppDynamicParameter(group: 'push-stops', name: 'price-to-use')]
    public function priceToUseWhenPushStopsToExchange(): TriggerBy
    {
        $position = $this->position;
        $ticker = $this->ticker;

        $liquidationParameters = $this->liquidationDynamicParametersFactory->fakeWithoutHandledMessage($position, $ticker);
        $distanceToUseMarkPrice = PushStopSettingsWrapper::rangeToUseWhileChooseMarkPrice($position) === PriceRangeLeadingToUseMarkPriceOptions::WarningRange
            ? $liquidationParameters->warningDistanceRaw()
            : $liquidationParameters->criticalDistance();

        $distanceToUseMarkPrice *= 2;

        $distanceWithLiquidation = $position->priceDistanceWithLiquidation($ticker);

        return $distanceWithLiquidation <= $distanceToUseMarkPrice ? TriggerBy::MarkPrice : TriggerBy::IndexPrice;
    }
}
