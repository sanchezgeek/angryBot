<?php

namespace App\Bot\Application\Service\Exchange\Account;

use App\Bot\Application\Service\Exchange\Dto\WalletBalance;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\Coin;

interface ExchangeAccountServiceInterface
{
    public function getSpotWalletBalance(Coin $coin): WalletBalance;

    public function getContractWalletBalance(Coin $coin): WalletBalance;

    public function interTransferFromSpotToContract(Coin $coin, float $amount): void;

    public function interTransferFromContractToSpot(Coin $coin, float $amount): void;

    public function getCachedTotalBalance(Symbol $symbol): float;
}
