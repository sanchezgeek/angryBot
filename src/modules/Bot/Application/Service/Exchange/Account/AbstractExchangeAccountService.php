<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Exchange\Account;

use App\Bot\Domain\ValueObject\Symbol;
use App\Value\CachedValue;

abstract class AbstractExchangeAccountService implements ExchangeAccountServiceInterface
{
    /** @var CachedValue[] */
    private array $balanceHotCache = [];

    public function getCachedTotalBalance(Symbol $symbol): float
    {
        $coin = $symbol->associatedCoin();
        $balance = $this->balanceHotCache[$coin->value] ?? (
            $this->balanceHotCache[$coin->value] = new CachedValue(
                function () use ($coin) {
                    $spotBalance = $this->getSpotWalletBalance($coin);
                    $contractBalance = $this->getContractWalletBalance($coin);

                    return $spotBalance->total->value() + $contractBalance->total->value();
                }
            )
        );

        return $balance->get();
    }
}
