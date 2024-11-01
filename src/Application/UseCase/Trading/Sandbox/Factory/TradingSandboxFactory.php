<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Factory;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceHandler;
use App\Application\UseCase\Trading\Sandbox\TradingSandbox;
use App\Application\UseCase\Trading\Sandbox\TradingSandboxInterface;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Service\OrderCostCalculator;

readonly class TradingSandboxFactory implements TradingSandboxFactoryInterface
{
    public function __construct(
        private OrderCostCalculator                 $orderCostCalculator,
        private CalcPositionLiquidationPriceHandler $positionLiquidationCalculator,
        private SandboxStateFactoryInterface        $sandboxStateFactory,
    ) {
    }

    public function empty(Symbol $symbol, bool $debug = false): TradingSandboxInterface
    {
        return new TradingSandbox($this->orderCostCalculator, $this->positionLiquidationCalculator, $symbol, $debug);
    }

    public function byCurrentState(Symbol $symbol, bool $debug = false): TradingSandboxInterface
    {
        $sandbox = $this->empty($symbol);
        $sandbox->setState($this->sandboxStateFactory->byCurrentTradingAccountState($symbol));

        return $sandbox;
    }
}
