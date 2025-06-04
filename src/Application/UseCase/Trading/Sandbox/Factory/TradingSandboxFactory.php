<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Factory;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceHandler;
use App\Application\UseCase\Trading\Sandbox\TradingSandbox;
use App\Application\UseCase\Trading\Sandbox\TradingSandboxInterface;
use App\Domain\Order\Service\OrderCostCalculator;
use App\Trading\Domain\Symbol\SymbolInterface;

readonly class TradingSandboxFactory implements TradingSandboxFactoryInterface
{
    public function __construct(
        private OrderCostCalculator                 $orderCostCalculator,
        private CalcPositionLiquidationPriceHandler $positionLiquidationCalculator,
        private SandboxStateFactoryInterface        $sandboxStateFactory,
    ) {
    }

    public function empty(SymbolInterface $symbol, bool $debug = false): TradingSandboxInterface
    {
        return new TradingSandbox($this->orderCostCalculator, $this->positionLiquidationCalculator, $symbol, $debug);
    }

    public function byCurrentState(SymbolInterface $symbol, bool $debug = false): TradingSandboxInterface
    {
        $sandbox = $this->empty($symbol);
        $sandbox->setState($this->sandboxStateFactory->byCurrentTradingAccountState($symbol));

        return $sandbox;
    }
}
