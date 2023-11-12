<?php

namespace App\Domain\Coin;

/**
 * @see https://bybit-exchange.github.io/docs/v5/account/wallet-balance
 */
enum Coin: string
{
    case USDT = 'USDT';
    case BTC = 'BTC';

    private const COIN_COST_PRECISION = [
        self::USDT->value => 3,
        self::BTC->value => 8,
    ];

    public function coinCostPrecision(): int
    {
        return self::COIN_COST_PRECISION[$this->value];
    }
}
