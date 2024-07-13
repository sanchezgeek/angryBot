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
final readonly class WalletBalance implements JsonSerializable, Stringable
{
    public CoinAmount $total;
    public CoinAmount $available;

    public function __construct(
        public AccountType $accountType,
        public Coin        $assetCoin,
        float $totalBalance,
        float $availableBalance,
    ) {
        $this->total = new CoinAmount($this->assetCoin, $totalBalance);
        $this->available = new CoinAmount($this->assetCoin, $availableBalance);
    }

    public function total(): float
    {
        return $this->total->value();
    }

    public function available(): float
    {
        return $this->available->value();
    }

    public function __toString(): string
    {
        if ($this->accountType === AccountType::SPOT) {
            return sprintf('%s available | %s total', $this->available, $this->total);
        }

        return sprintf('%s available | %s total', $this->available, $this->total);
    }

    public function jsonSerialize(): string
    {
        return (string)$this;
    }
}
