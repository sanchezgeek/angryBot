<?php

namespace App\Infrastructure\ByBit\API\V5\Enum\Account;

/**
 * @see https://bybit-exchange.github.io/docs/v5/enum#accounttype
 */
enum AccountType: string
{
    case SPOT = 'SPOT';
    case CONTRACT = 'CONTRACT';
    case FUNDING = 'FUND';
    case UNIFIED = 'UNIFIED';
}
