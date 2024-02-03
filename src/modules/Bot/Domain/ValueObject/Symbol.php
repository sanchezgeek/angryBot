<?php

declare(strict_types=1);

namespace App\Bot\Domain\ValueObject;

use App\Domain\Coin\Coin;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;

enum Symbol: string
{
    case BTCUSDT = 'BTCUSDT';
    case BTCUSD = 'BTCUSD';

    private const ASSOCIATED_COINS = [
        self::BTCUSDT->value => Coin::USDT,
        self::BTCUSD->value => Coin::BTC, // inverse?
    ];

    private const ASSOCIATED_CATEGORIES = [
        self::BTCUSDT->value => AssetCategory::linear,
        self::BTCUSD->value => AssetCategory::inverse,
    ];

    public function associatedCoin(): Coin
    {
        return self::ASSOCIATED_COINS[$this->value];
    }

    public function associatedCategory(): AssetCategory
    {
        return self::ASSOCIATED_CATEGORIES[$this->value];
    }
}
