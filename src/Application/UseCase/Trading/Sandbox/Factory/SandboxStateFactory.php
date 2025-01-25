<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Factory;

use App\Application\UseCase\Trading\Sandbox\SandboxState;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\ValueObject\Symbol;

/**
 * @todo | sandbox | Move to ... where?
 */
final readonly class SandboxStateFactory implements SandboxStateFactoryInterface
{
    public function __construct(
        private ExchangeServiceInterface $exchangeService,
        private PositionServiceInterface $positionService,
        private ExchangeAccountServiceInterface $exchangeAccountService,
    ) {
    }

    public function byCurrentTradingAccountState(Symbol $symbol): SandboxState
    {
        $ticker = $this->exchangeService->ticker($symbol);
        $positions = $this->positionService->getPositions($symbol);
        $contractBalance = $this->exchangeAccountService->getContractWalletBalance($symbol->associatedCoin());

        return new SandboxState($ticker, $contractBalance, ...$positions);
    }
}
