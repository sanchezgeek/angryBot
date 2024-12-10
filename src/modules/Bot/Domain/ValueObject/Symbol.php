<?php

declare(strict_types=1);

namespace App\Bot\Domain\ValueObject;

use App\Domain\Coin\Coin;
use App\Domain\Price\Price;
use App\Domain\Price\PriceFactory;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;

use function pow;

enum Symbol: string
{
    case BTCUSDT = 'BTCUSDT';
    case BTCUSD = 'BTCUSD';

    case ETHUSDT = 'ETHUSDT';
    case XRPUSDT = 'XRPUSDT';
    case TONUSDT = 'TONUSDT';
    case SOLUSDT = 'SOLUSDT';
    case ADAUSDT = 'ADAUSDT';
    case LINKUSDT = 'LINKUSDT';
    case WIFUSDT = 'WIFUSDT';
    case OPUSDT = 'OPUSDT';
    case DOGEUSDT = 'DOGEUSDT';
    case SUIUSDT = 'SUIUSDT';

    private const ASSOCIATED_COINS = [
        self::BTCUSDT->value => Coin::USDT,
        self::BTCUSD->value => Coin::BTC,
        self::ETHUSDT->value => Coin::USDT,
        self::TONUSDT->value => Coin::USDT,
        self::XRPUSDT->value => Coin::USDT,
        self::SOLUSDT->value => Coin::USDT,
        self::ADAUSDT->value => Coin::USDT,
        self::LINKUSDT->value => Coin::USDT,
        self::WIFUSDT->value => Coin::USDT,
        self::OPUSDT->value => Coin::USDT,
        self::DOGEUSDT->value => Coin::USDT,
        self::SUIUSDT->value => Coin::USDT,
    ];

    private const ASSOCIATED_CATEGORIES = [
        self::BTCUSDT->value => AssetCategory::linear,
        self::BTCUSD->value => AssetCategory::inverse,
        self::ETHUSDT->value => AssetCategory::linear,
        self::XRPUSDT->value => AssetCategory::linear,
        self::TONUSDT->value => AssetCategory::linear,
        self::SOLUSDT->value => AssetCategory::linear,
        self::ADAUSDT->value => AssetCategory::linear,
        self::LINKUSDT->value => AssetCategory::linear,
        self::WIFUSDT->value => AssetCategory::linear,
        self::OPUSDT->value => AssetCategory::linear,
        self::DOGEUSDT->value => AssetCategory::linear,
        self::SUIUSDT->value => AssetCategory::linear,
    ];

    private const TRADING_PRICE_PRECISION = [
        self::BTCUSDT->value => 2,
        self::BTCUSD->value => 2,
        self::LINKUSDT->value => 3,
        self::ADAUSDT->value => 4,
        self::TONUSDT->value => 4,
        self::ETHUSDT->value => 2,
        self::ETHUSDT->value => 2,
        self::XRPUSDT->value => 4,
        self::SOLUSDT->value => 3,
        self::WIFUSDT->value => 4,
        self::OPUSDT->value => 4,
        self::DOGEUSDT->value => 5,
        self::SUIUSDT->value => 5,
    ];

    private const STOP_TRIGGER_DELTA = [
        self::BTCUSDT->value => 25,
        self::BTCUSD->value => 25,
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

    public function stopDefaultTriggerDelta(): float
    {
        if (isset(self::STOP_TRIGGER_DELTA[$this->value])) {
            return self::STOP_TRIGGER_DELTA[$this->value];
        }

        $pricePrecision = $this->pricePrecision();

        return round(pow(0.1, $pricePrecision - 1), $pricePrecision);
    }

    public function byMarketTd(): float
    {
        $pricePrecision = $this->pricePrecision();

        return round(pow(0.1, $pricePrecision), $pricePrecision + 1);
    }

    public function makePrice(float $value): Price
    {
        $factory = new PriceFactory($this);

        return $factory->make($value);
    }
}
