<?php

declare(strict_types=1);

namespace App\Bot\Domain\ValueObject;

use App\Domain\Coin\Coin;
use App\Domain\Price\Price;
use App\Domain\Price\PriceFactory;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use ValueError;

use function ceil;
use function pow;
use function strlen;

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
    case AAVEUSDT = 'AAVEUSDT';
    case AVAXUSDT = 'AVAXUSDT';
    case LTCUSDT = 'LTCUSDT';
    case BNBUSDT = 'BNBUSDT';
    case ENSUSDT = 'ENSUSDT';

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
        self::AAVEUSDT->value => Coin::USDT,
        self::AVAXUSDT->value => Coin::USDT,
        self::LTCUSDT->value => Coin::USDT,
        self::BNBUSDT->value => Coin::USDT,
        self::ENSUSDT->value => Coin::USDT,
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
        self::AAVEUSDT->value => AssetCategory::linear,
        self::AVAXUSDT->value => AssetCategory::linear,
        self::LTCUSDT->value => AssetCategory::linear,
        self::BNBUSDT->value => AssetCategory::linear,
        self::ENSUSDT->value => AssetCategory::linear,
    ];

    private const TRADING_PRICE_PRECISION = [
        self::BTCUSDT->value => 2,
        self::BTCUSD->value => 2,
        self::LINKUSDT->value => 3,
        self::ADAUSDT->value => 4,
        self::TONUSDT->value => 4,
        self::ETHUSDT->value => 2,
        self::XRPUSDT->value => 4,
        self::SOLUSDT->value => 3,
        self::WIFUSDT->value => 4,
        self::OPUSDT->value => 4,
        self::DOGEUSDT->value => 5,
        self::SUIUSDT->value => 5,
        self::AAVEUSDT->value => 2,
        self::AVAXUSDT->value => 2,
        self::LTCUSDT->value => 2,
        self::BNBUSDT->value => 2,
        self::ENSUSDT->value => 3,
    ];

    private const MIN_ORDER_QTY = [
        self::BTCUSDT->value => 0.001,
        self::BTCUSD->value => 0.001,
        self::LINKUSDT->value => 0.1,
        self::ADAUSDT->value => 1,
        self::TONUSDT->value => 0.1,
        self::ETHUSDT->value => 0.01,
        self::XRPUSDT->value => 1,
        self::SOLUSDT->value => 0.1,
        self::WIFUSDT->value => 1,
        self::OPUSDT->value => 0.1,
        self::DOGEUSDT->value => 1,
        self::SUIUSDT->value => 10,
        self::AAVEUSDT->value => 0.01,
        self::AVAXUSDT->value => 0.1,
        self::LTCUSDT->value => 0.01,
        self::BNBUSDT->value => 0.01,
        self::ENSUSDT->value => 0.1,
    ];

    private const MIN_NOTIONAL_ORDER_VALUE = [
        self::BTCUSDT->value => 5,
        self::BTCUSD->value => 5,
        self::LINKUSDT->value => 5,
        self::ADAUSDT->value => 5,
        self::TONUSDT->value => 5,
        self::ETHUSDT->value => 5,
        self::XRPUSDT->value => 5,
        self::SOLUSDT->value => 5,
        self::WIFUSDT->value => 5,
        self::OPUSDT->value => 5,
        self::DOGEUSDT->value => 5,
        self::SUIUSDT->value => 5,
        self::AAVEUSDT->value => 5,
        self::AVAXUSDT->value => 5,
        self::LTCUSDT->value => 5,
        self::BNBUSDT->value => 5,
        self::ENSUSDT->value => 5,
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

    public function makePrice(float $value): Price
    {
        $factory = new PriceFactory($this);

        return $factory->make($value);
    }

    public function minOrderQty(): float|int
    {
        return self::MIN_ORDER_QTY[$this->value];
    }

    public function minNotionalOrderValue(): float|int
    {
        return self::MIN_NOTIONAL_ORDER_VALUE[$this->value];
    }

    public function contractSizePrecision(): ?int
    {
        $minOrderQty = $this->minOrderQty();

        if (is_int($minOrderQty)) {
            return 0;
        }

        $parts = explode('.', (string)$minOrderQty);

        return strlen($parts[1]);
    }

    public function roundVolume(float $volume): float
    {
        $value = round($volume, $this->contractSizePrecision());
        if ($value < $this->minOrderQty()) {
            $value = $this->minOrderQty();
        }

        return $value;
    }

    public function roundVolumeUp(float $volume): float
    {
        $precision = $this->contractSizePrecision();

        $fig = 10 ** $precision;
        $value = (ceil($volume * $fig) / $fig);

        if ($value < $this->minOrderQty()) {
            $value = $this->minOrderQty();
        }

        return $value;
    }

    public static function fromShortName(string $name): self
    {
        try {
            $symbol = self::from($name);
        } catch (ValueError) {
            $symbol = self::from($name . 'USDT');
        }

        return $symbol;
    }
}
