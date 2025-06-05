<?php

declare(strict_types=1);

namespace App\Bot\Domain\ValueObject;

use App\Domain\Coin\Coin;
use App\Domain\Coin\CoinAmount;
use App\Domain\Price\Exception\PriceCannotBeLessThanZero;
use App\Domain\Price\SymbolPrice;
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
    case AGLDUSDT = 'AGLDUSDT';
    case MORPHOUSDT = 'MORPHOUSDT';
    case PUFFERUSDT = 'PUFFERUSDT';
    case FARTCOINUSDT = 'FARTCOINUSDT';
    case AIUSDT = 'AIUSDT';
    case GRASSUSDT = 'GRASSUSDT';
    case CVXUSDT = 'CVXUSDT';
    case SPXUSDT = 'SPXUSDT';
    case GRIFFAINUSDT = 'GRIFFAINUSDT';
    case AI16ZUSDT = 'AI16ZUSDT';
    case SWARMSUSDT = 'SWARMSUSDT';
    case HIVEUSDT = 'HIVEUSDT';
    case DEEPUSDT = 'DEEPUSDT';
    case PENGUUSDT = 'PENGUUSDT';
    case SONICUSDT = 'SONICUSDT';
    case SOLOUSDT = 'SOLOUSDT';
    case AVAAIUSDT = 'AVAAIUSDT';
    case RAYDIUMUSDT = 'RAYDIUMUSDT';
    case ARCUSDT = 'ARCUSDT';
    case HBARUSDT = 'HBARUSDT';
    case XCNUSDT = 'XCNUSDT';
    case L3USDT = 'L3USDT';
    case FIREUSDT = 'FIREUSDT';
    case TOSHI1000USDT = '1000TOSHIUSDT';
    case TRUMPUSDT = 'TRUMPUSDT';
    case NCUSDT = 'NCUSDT';
    case UROUSDT = 'UROUSDT';
    case MOTHERUSDT = 'MOTHERUSDT';
    case WHYUSDT10000 = '10000WHYUSDT';
    case CHILLGUYUSDT = 'CHILLGUYUSDT';
    case CLOUDUSDT = 'CLOUDUSDT';
    case FBUSDT = 'FBUSDT';
    case OMUSDT = 'OMUSDT';
    case ACHUSDT = 'ACHUSDT';
    case RUNEUSDT = 'RUNEUSDT';
    case DOTUSDT = 'DOTUSDT';
    case GPSUSDT = 'GPSUSDT';
    case LAIUSDT = 'LAIUSDT';
    case STORJUSDT = 'STORJUSDT';
    case BROCCOLIUSDT = 'BROCCOLIUSDT';
    case BSWUSDT = 'BSWUSDT';
    case RSS3USDT = 'RSS3USDT';
    case FLMUSDT = 'FLMUSDT';
    case FOXYUSDT = 'FOXYUSDT';
    case TUTUSDT = 'TUTUSDT';
    case THETAUSDT = 'THETAUSDT';
    case UNIUSDT = 'UNIUSDT';
    case TAOUSDT = 'TAOUSDT';
    case STPTUSDT = 'STPTUSDT';
    case SOLAYERUSDT = 'SOLAYERUSDT';
    case ZBCNUSDT = 'ZBCNUSDT';
    case NEIROCTO1000USDT = '1000NEIROCTOUSDT';
    case MOODENGUSDT = 'MOODENGUSDT';
    case KAITOUSDT = 'KAITOUSDT';
    case FTNUSDT = 'FTNUSDT';
    case PAXGUSDT = 'PAXGUSDT';
    case XMRUSDT = 'XMRUSDT';
    case WCTUSDT = 'WCTUSDT';
    case SYRUPUSDT = 'SYRUPUSDT';
    case CVCUSDT = 'CVCUSDT';
    case MASAUSDT = 'MASAUSDT';
    case LPTUSDT = 'LPTUSDT';
    case NEIROETHUSDT = 'NEIROETHUSDT';
    case SOLVUSDT = 'SOLVUSDT';
    case FLOCKUSDT = 'FLOCKUSDT';
    case MASKUSDT = 'MASKUSDT';
    case BUSDT = 'BUSDT';
    case TRBUSDT = 'TRBUSDT';
    case XEMUSDT = 'XEMUSDT';

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
        self::AGLDUSDT->value => 4,
        self::MORPHOUSDT->value => 4,
        self::PUFFERUSDT->value => 4,
        self::FARTCOINUSDT->value => 4,
        self::AIUSDT->value => 4,
        self::GRASSUSDT->value => 4,
        self::CVXUSDT->value => 3,
        self::SPXUSDT->value => 4,
        self::GRIFFAINUSDT->value => 5,
        self::AI16ZUSDT->value => 4,
        self::SWARMSUSDT->value => 5,
        self::HIVEUSDT->value => 4,
        self::DEEPUSDT->value => 5,
        self::PENGUUSDT->value => 5,
        self::SONICUSDT->value => 4,
        self::SOLOUSDT->value => 4,
        self::AVAAIUSDT->value => 5,
        self::RAYDIUMUSDT->value => 4,
        self::ARCUSDT->value => 5,
        self::HBARUSDT->value => 5,
        self::XCNUSDT->value => 7,
        self::L3USDT->value => 5,
        self::FIREUSDT->value => 4,
        self::TOSHI1000USDT->value => 4,
        self::TRUMPUSDT->value => 3,
        self::NCUSDT->value => 5,
        self::UROUSDT->value => 5,
        self::MOTHERUSDT->value => 6,
        self::WHYUSDT10000->value => 6,
        self::CHILLGUYUSDT->value => 4,
        self::CLOUDUSDT->value => 4,
        self::FBUSDT->value => 3,
        self::OMUSDT->value => 5,
        self::ACHUSDT->value => 6,
        self::RUNEUSDT->value => 3,
        self::DOTUSDT->value => 3,
        self::GPSUSDT->value => 5,
        self::LAIUSDT->value => 7,
        self::STORJUSDT->value => 4,
        self::BROCCOLIUSDT->value => 5,
        self::BSWUSDT->value => 5,
        self::RSS3USDT->value => 5,
        self::FLMUSDT->value => 5,
        self::FOXYUSDT->value => 6,
        self::TUTUSDT->value => 5,
        self::THETAUSDT->value => 4,
        self::UNIUSDT->value => 3,
        self::TAOUSDT->value => 2,
        self::STPTUSDT->value => 5,
        self::SOLAYERUSDT->value => 4,
        self::ZBCNUSDT->value => 7,
        self::NEIROCTO1000USDT->value => 5,
        self::MOODENGUSDT->value => 5,
        self::KAITOUSDT->value => 4,
        self::FTNUSDT->value => 4,
        self::PAXGUSDT->value => 2,
        self::XMRUSDT->value => 2,
        self::WCTUSDT->value => 4,
        self::SYRUPUSDT->value => 5,
        self::CVCUSDT->value => 5,
        self::MASAUSDT->value => 5,
        self::LPTUSDT->value => 3,
        self::NEIROETHUSDT->value => 5,
        self::SOLVUSDT->value => 5,
        self::FLOCKUSDT->value => 5,
        self::MASKUSDT->value => 3,
        self::BUSDT->value => 5,
        self::TRBUSDT->value => 2,
        self::XEMUSDT->value => 5,
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
        self::AGLDUSDT->value => 0.1,
        self::MORPHOUSDT->value => 1,
        self::PUFFERUSDT->value => 1,
        self::FARTCOINUSDT->value => 1,
        self::AIUSDT->value => 0.1,
        self::GRASSUSDT->value => 1,
        self::CVXUSDT->value => 0.01,
        self::SPXUSDT->value => 1,
        self::GRIFFAINUSDT->value => 1,
        self::AI16ZUSDT->value => 1,
        self::SWARMSUSDT->value => 1,
        self::HIVEUSDT->value => 1,
        self::DEEPUSDT->value => 10,
        self::PENGUUSDT->value => 1,
        self::SONICUSDT->value => 1,
        self::SOLOUSDT->value => 1,
        self::AVAAIUSDT->value => 1,
        self::RAYDIUMUSDT->value => 0.1,
        self::ARCUSDT->value => 1,
        self::HBARUSDT->value => 1,
        self::XCNUSDT->value => 10,
        self::L3USDT->value => 10,
        self::FIREUSDT->value => 0.1,
        self::TOSHI1000USDT->value => 1,
        self::TRUMPUSDT->value => 0.1,
        self::NCUSDT->value => 1,
        self::UROUSDT->value => 10,
        self::MOTHERUSDT->value => 10,
        self::WHYUSDT10000->value => 100,
        self::CHILLGUYUSDT->value => 1,
        self::CLOUDUSDT->value => 1,
        self::FBUSDT->value => 0.1,
        self::OMUSDT->value => 1,
        self::ACHUSDT->value => 10,
        self::RUNEUSDT->value => 0.1,
        self::DOTUSDT->value => 0.1,
        self::GPSUSDT->value => 1,
        self::LAIUSDT->value => 1,
        self::STORJUSDT->value => 1,
        self::BROCCOLIUSDT->value => 1,
        self::BSWUSDT->value => 1,
        self::RSS3USDT->value => 1,
        self::FLMUSDT->value => 1,
        self::FOXYUSDT->value => 1,
        self::TUTUSDT->value => 1,
        self::THETAUSDT->value => 1,
        self::UNIUSDT->value => 0.1,
        self::TAOUSDT->value => 0.001,
        self::STPTUSDT->value => 1,
        self::SOLAYERUSDT->value => 1,
        self::ZBCNUSDT->value => 1,
        self::NEIROCTO1000USDT->value => 1,
        self::MOODENGUSDT->value => 1,
        self::KAITOUSDT->value => 1,
        self::FTNUSDT->value => 0.1,
        self::PAXGUSDT->value => 0.001,
        self::XMRUSDT->value => 0.01,
        self::WCTUSDT->value => 1,
        self::SYRUPUSDT->value => 1,
        self::CVCUSDT->value => 1,
        self::MASAUSDT->value => 1,
        self::LPTUSDT->value => 0.1,
        self::NEIROETHUSDT->value => 1,
        self::SOLVUSDT->value => 1,
        self::FLOCKUSDT->value => 1,
        self::MASKUSDT->value => 0.1,
        self::BUSDT->value => 1,
        self::TRBUSDT->value => 0.01,
        self::XEMUSDT->value => 1,
    ];

    private const MIN_NOTIONAL_ORDER_VALUE = [

    ];

    private const ASSOCIATED_COINS = [
        self::BTCUSD->value => Coin::BTC,
    ];

    private const ASSOCIATED_CATEGORIES = [
        self::BTCUSD->value => AssetCategory::inverse,
    ];

    private const STOP_TRIGGER_DELTA = [
        self::BTCUSDT->value => 25,
        self::BTCUSD->value => 25,
    ];

    private const VERY_SHORT_NAMES = [

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

    public function minimalPriceMove(): float
    {
        $pricePrecision = $this->pricePrecision();

        return round(pow(0.1, $pricePrecision), $pricePrecision);
    }

    /**
     * @throws PriceCannotBeLessThanZero
     */
    public function makePrice(float $value): SymbolPrice
    {
        return SymbolPrice::create($value, $this);
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

    public function roundVolumeDown(float $volume): float
    {
        $precision = $this->contractSizePrecision();

        $value = floor($volume*pow(10,$precision))/pow(10,$precision);
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

    public function shortName(): string
    {
        if (str_contains($this->value, 'USDT')) {
            return str_replace('USDT', '', $this->value);
        }

        return $this->value;
    }

    public function veryShortName(): string
    {
        return substr(self::VERY_SHORT_NAMES[$this->value] ?? $this->shortName(), 0, 3);
    }

    public function associatedCoinAmount(float $amount): CoinAmount
    {
        return new CoinAmount($this->associatedCoin(), $amount);
    }
}
