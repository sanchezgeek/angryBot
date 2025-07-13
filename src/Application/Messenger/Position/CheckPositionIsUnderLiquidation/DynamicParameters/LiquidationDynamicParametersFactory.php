<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\DynamicParameters;

use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\CheckPositionIsUnderLiquidation;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;

final readonly class LiquidationDynamicParametersFactory implements LiquidationDynamicParametersFactoryInterface
{
    public function __construct(
        private AppSettingsProviderInterface $settingsProvider,
        private TradingParametersProviderInterface $tradingParametersProvider,
    ) {
    }

    public function create(
        CheckPositionIsUnderLiquidation $handledMessage,
        Position $position,
        Ticker $ticker,
    ): LiquidationDynamicParameters {
        return new LiquidationDynamicParameters(
            tradingParametersProvider: $this->tradingParametersProvider,
            settingsProvider: $this->settingsProvider,
            position: $position,
            ticker: $ticker,
            handledMessage: $handledMessage
        );
    }

    public function fakeWithoutHandledMessage(Position $position, Ticker $ticker): LiquidationDynamicParameters
    {
        return new LiquidationDynamicParameters(
            tradingParametersProvider: $this->tradingParametersProvider,
            settingsProvider: $this->settingsProvider,
            position: $position,
            ticker: $ticker
        );
    }
}
