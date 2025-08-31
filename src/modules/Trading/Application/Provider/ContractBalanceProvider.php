<?php

declare(strict_types=1);

namespace App\Trading\Application\Provider;

use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\Dto\ContractBalance;
use App\Domain\Coin\Coin;
use App\Trading\Contract\ContractBalanceProviderInterface;

final readonly class ContractBalanceProvider implements ContractBalanceProviderInterface
{
    public function __construct(private ExchangeAccountServiceInterface $exchangeAccountService)
    {
    }

    public function getContractWalletBalance(Coin $coin): ContractBalance
    {
        return $this->exchangeAccountService->getContractWalletBalance($coin);
    }
}
