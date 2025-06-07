<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Factory;

use App\Application\UseCase\Trading\Sandbox\SandboxState;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Infrastructure\ByBit\Service\Account\ByBitExchangeAccountService;
use App\Trading\Domain\Symbol\SymbolInterface;

/**
 * @todo | sandbox | Move to ... where?
 */
final readonly class SandboxStateFactory implements SandboxStateFactoryInterface
{
    public function __construct(
        private ExchangeServiceInterface $exchangeService,
        private PositionServiceInterface $positionService,
        private ByBitExchangeAccountService $exchangeAccountService,
    ) {
    }

    public function byCurrentTradingAccountState(SymbolInterface $symbol): SandboxState
    {
        $ticker = $this->exchangeService->ticker($symbol);
        $positions = $this->positionService->getPositions($symbol);
        $contractBalance = $this->exchangeAccountService->getContractWalletBalance($symbol->associatedCoin());
        $fundsAvailableForLiquidation = $this->exchangeAccountService->calcFundsAvailableForLiquidation($symbol, $contractBalance);

        return new SandboxState($ticker, $contractBalance, $fundsAvailableForLiquidation, ...$positions);
    }
}
