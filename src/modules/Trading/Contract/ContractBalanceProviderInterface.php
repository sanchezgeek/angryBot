<?php

declare(strict_types=1);

namespace App\Trading\Contract;

use App\Bot\Application\Service\Exchange\Dto\ContractBalance;
use App\Domain\Coin\Coin;

interface ContractBalanceProviderInterface
{
    public function getContractWalletBalance(Coin $coin): ContractBalance;
}
