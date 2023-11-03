<?php

declare(strict_types=1);

namespace App\Bot\Domain\ValueObject;

use App\Infrastructure\ByBit\API\V5\Enum\Account\Coin;

enum Symbol: string
{
    case BTCUSDT = 'BTCUSDT';
    case BTCUSD = 'BTCUSD';

    private const ASSOCIATED_COINS = [
        self::BTCUSDT->value => Coin::USDT,
        self::BTCUSD->value => Coin::BTC, // inverse?
    ];

    public function associatedCoin(): Coin
    {
        return self::ASSOCIATED_COINS[$this->value];
    }
}
