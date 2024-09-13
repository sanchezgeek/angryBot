<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Factory;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceHandler;
use App\Application\UseCase\Trading\Sandbox\SandboxState;
use App\Application\UseCase\Trading\Sandbox\TradingSandbox;
use App\Application\UseCase\Trading\Sandbox\TradingSandboxInterface;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Service\OrderCostCalculator;

readonly class TradingSandboxFactory implements TradingSandboxFactoryInterface
{
    public function __construct(
        private OrderCostCalculator $orderCostCalculator,
        private CalcPositionLiquidationPriceHandler $positionLiquidationCalculator,
        private ExchangeServiceInterface $exchangeService,
        private PositionServiceInterface $positionService,
        private ExchangeAccountServiceInterface $exchangeAccountService,
    ) {
    }

    public function empty(Symbol $symbol, bool $debug = false): TradingSandboxInterface
    {
        return new TradingSandbox($this->orderCostCalculator, $this->positionLiquidationCalculator, $symbol, $debug);
    }

    public function byCurrentState(Symbol $symbol, bool $debug = false): TradingSandboxInterface
    {
        $currentState = $this->getCurrentState($symbol);

        return $this->empty($symbol)->setState($currentState);
    }

    private function getCurrentState(Symbol $symbol): SandboxState
    {
        $ticker = $this->exchangeService->ticker($symbol);
        $positions = $this->positionService->getPositions($symbol);
        $balance = $this->exchangeAccountService->getContractWalletBalance($symbol->associatedCoin());

        return new SandboxState($ticker, $balance->free, ...$positions);
    }
}
