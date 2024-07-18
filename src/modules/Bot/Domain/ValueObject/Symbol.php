<?php

declare(strict_types=1);

namespace App\Bot\Domain\ValueObject;

use App\Domain\Coin\Coin;
use App\Domain\Price\Price;
use App\Domain\Price\PriceFactory;
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

    private const TRADING_PRICE_PRECISION = [
        self::BTCUSDT->value => 2,
        self::BTCUSD->value => 2,
    ];

    public function associatedCoin(): Coin
    {
        return self::ASSOCIATED_COINS[$this->value];
    }

    public function associatedCategory(): AssetCategory
    {
        return self::ASSOCIATED_CATEGORIES[$this->value];
    }

    public function pricePrecision(): int
    {
        return self::TRADING_PRICE_PRECISION[$this->value];
    }

    public function makePrice(float $value): Price
    {
        $factory = new PriceFactory($this);

        return $factory->make($value);
    }
}
