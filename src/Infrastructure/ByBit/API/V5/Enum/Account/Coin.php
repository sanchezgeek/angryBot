<?php

namespace App\Infrastructure\ByBit\API\V5\Enum\Account;

/**
 * @see https://bybit-exchange.github.io/docs/v5/account/wallet-balance
 */
enum Coin: string
{
    case USDT = 'USDT';
    case BTC = 'BTC';
}
