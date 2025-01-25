<?php

declare(strict_types=1);


namespace App\Bot\Application\Service\Exchange\Dto;

use App\Domain\Coin\Coin;
use App\Domain\Coin\CoinAmount;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use JsonSerializable;
use Stringable;

use function sprintf;

/**
 * @todo | Или это уже Domain?
 * @see \App\Tests\Unit\Bot\Application\Service\Exchange\Dto\WalletBalanceTest
 */
final readonly class ContractBalance implements JsonSerializable, Stringable
{
    public CoinAmount $total;
    public CoinAmount $available;
    public CoinAmount $free;
    public CoinAmount $freeForLiquidation;
    public CoinAmount $availableForTrade;

    public function __construct(
        public Coin        $assetCoin,
        CoinAmount|float $total,
        CoinAmount|float $available,
        CoinAmount|float $free,
        CoinAmount|float $freeForLiquidation,
        CoinAmount|float $availableForTrade = null,
    ) {
        $this->total = new CoinAmount($this->assetCoin, $total);
        $this->available = new CoinAmount($this->assetCoin, $available);
        $this->free = new CoinAmount($this->assetCoin, $free);
        $this->freeForLiquidation = new CoinAmount($this->assetCoin, $freeForLiquidation);
        $this->availableForTrade = new CoinAmount($this->assetCoin, $availableForTrade ?? $available);
    }

    public function total(): float
    {
        return $this->total->value();
    }

    public function available(): float
    {
        return $this->available->value();
    }

    public function free(): float
    {
        return $this->free->value();
    }

    public function availableForTrade(): float
    {
        return $this->availableForTrade->value();
    }

    public function __toString(): string
    {
        return sprintf('%s: %s availForTrade | %s available | %s free | %s free liq | %s total', AccountType::UNIFIED->name, $this->availableForTrade, $this->available, $this->free, $this->freeForLiquidation, $this->total);
    }

    public function jsonSerialize(): string
    {
        return (string)$this;
    }
}
