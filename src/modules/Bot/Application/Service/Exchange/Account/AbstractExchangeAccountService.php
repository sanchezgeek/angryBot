<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Exchange\Account;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Value\CachedValue;

abstract class AbstractExchangeAccountService implements ExchangeAccountServiceInterface
{
    /** @var CachedValue[] */
    private array $balanceHotCache = [];

    public function getCachedTotalBalance(SymbolInterface $symbol): float
    {
        $coin = $symbol->associatedCoin();
        $balance = $this->balanceHotCache[$coin->value] ?? (
            $this->balanceHotCache[$coin->value] = new CachedValue(
                function () use ($coin) {
                    $spotBalance = $this->getSpotWalletBalance($coin, true);
                    $contractBalance = $this->getContractWalletBalance($coin);

                    return $spotBalance->total() + $contractBalance->total();
                }
            )
        );

        return $balance->get();
    }
}
