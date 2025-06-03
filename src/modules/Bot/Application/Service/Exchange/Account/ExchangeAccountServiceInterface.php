<?php

namespace App\Bot\Application\Service\Exchange\Account;

use App\Bot\Application\Service\Exchange\Dto\ContractBalance;
use App\Bot\Application\Service\Exchange\Dto\SpotBalance;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Coin\Coin;

interface ExchangeAccountServiceInterface
{
    public function getSpotWalletBalance(Coin $coin, bool $suppressUTAWarning = false): SpotBalance;

    public function getContractWalletBalance(Coin $coin): ContractBalance;

    public function interTransferFromSpotToContract(Coin $coin, float $amount): void;

    public function interTransferFromContractToSpot(Coin $coin, float $amount): void;

    public function getCachedTotalBalance(SymbolInterface $symbol): float;
}
