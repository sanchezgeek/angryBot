<?php

namespace App\Bot\Application\Service\Exchange\Account;

use App\Bot\Application\Service\Exchange\Dto\ContractBalance;
use App\Bot\Application\Service\Exchange\Dto\SpotBalance;
use App\Domain\Coin\Coin;
use App\Trading\Domain\Symbol\SymbolInterface;

interface ExchangeAccountServiceInterface
{
    public function getSpotWalletBalance(Coin $coin, bool $suppressUTAWarning = false): SpotBalance;

    public function getContractWalletBalance(Coin $coin): ContractBalance;

    public function interTransferFromSpotToContract(Coin $coin, float $amount): void;

    public function interTransferFromContractToSpot(Coin $coin, float $amount): void;

    public function getCachedTotalBalance(SymbolInterface $symbol): float;
}
