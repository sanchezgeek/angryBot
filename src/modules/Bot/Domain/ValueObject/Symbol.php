<?php

declare(strict_types=1);

namespace App\Bot\Domain\ValueObject;

use App\Domain\Coin\Coin;
use App\Domain\Price\Price;
use App\Domain\Price\PriceFactory;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use ValueError;

use function ceil;
use function explode;
use function is_int;
use function pow;
use function round;
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
    case MOVEUSDT = 'MOVEUSDT';
    case ZENUSDT = 'ZENUSDT';
    case HYPEUSDT = 'HYPEUSDT';
    case MNTUSDT = 'MNTUSDT';
    case VIRTUALUSDT = 'VIRTUALUSDT';
    case GNOUSDT = 'GNOUSDT';
    case ZECUSDT = 'ZECUSDT';
    case PHAUSDT = 'PHAUSDT';
    case A8USDT = 'A8USDT';
    case NSUSDT = 'NSUSDT';
    case VELOUSDT = 'VELOUSDT';
    case DEXEUSDT = 'DEXEUSDT';
    case ATAUSDT = 'ATAUSDT';

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
        self::MOVEUSDT->value => 4,
        self::ZENUSDT->value => 3,
        self::HYPEUSDT->value => 3,
        self::MNTUSDT->value => 5,
        self::VIRTUALUSDT->value => 4,
        self::GNOUSDT->value => 2,
        self::ZECUSDT->value => 2,
        self::PHAUSDT->value => 5,
        self::A8USDT->value => 5,
        self::NSUSDT->value => 4,
        self::VELOUSDT->value => 6,
        self::DEXEUSDT->value => 3,
        self::ATAUSDT->value => 5,
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
        self::MOVEUSDT->value => 1,
        self::ZENUSDT->value => 0.1,
        self::HYPEUSDT->value => 0.01,
        self::MNTUSDT->value => 1,
        self::VIRTUALUSDT->value => 1,
        self::GNOUSDT->value => 0.001,
        self::ZECUSDT->value => 0.01,
        self::PHAUSDT->value => 1,
        self::A8USDT->value => 1,
        self::NSUSDT->value => 1,
        self::VELOUSDT->value => 1,
        self::DEXEUSDT->value => 0.1,
        self::ATAUSDT->value => 10,
    ];

    private const MIN_NOTIONAL_ORDER_VALUE = [];
    private const ASSOCIATED_COINS = [
        self::BTCUSD->value => Coin::BTC,
    ];
    private const ASSOCIATED_CATEGORIES = [
        self::BTCUSD->value => AssetCategory::inverse
    ];
    private const STOP_TRIGGER_DELTA = [
        self::BTCUSDT->value => 25,
        self::BTCUSD->value => 25,
    ];

    public function associatedCoin(): Coin
    {
        return self::ASSOCIATED_COINS[$this->value] ?? Coin::USDT;
    }

    public function associatedCategory(): AssetCategory
    {
        return self::ASSOCIATED_CATEGORIES[$this->value] ?? AssetCategory::linear;
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
        return self::MIN_NOTIONAL_ORDER_VALUE[$this->value] ?? 5;
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
