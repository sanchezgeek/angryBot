<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceHandler;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Service\OrderCostCalculator;

readonly class ExecutionSandboxFactory
{
    public function __construct(
        private OrderCostCalculator $orderCostCalculator,
        private CalcPositionLiquidationPriceHandler $positionLiquidationCalculator,
        private ExchangeServiceInterface $exchangeService,
        private PositionServiceInterface $positionService,
        private ExchangeAccountServiceInterface $exchangeAccountService,
    ) {
    }

    public function make(Symbol $symbol, bool $debug = false): ExecutionSandbox
    {
        $ticker = $this->exchangeService->ticker($symbol);
        $positions = $this->positionService->getPositions($symbol);

        $balance = $this->exchangeAccountService->getContractWalletBalance($symbol->associatedCoin());

        return new ExecutionSandbox($this->orderCostCalculator, $this->positionLiquidationCalculator, $ticker, $positions, $balance->free, $debug);
    }
}