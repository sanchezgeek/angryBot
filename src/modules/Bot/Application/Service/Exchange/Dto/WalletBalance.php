<?php

declare(strict_types=1);


namespace App\Bot\Application\Service\Exchange\Dto;

use App\Domain\Coin\Coin;
use App\Domain\Coin\CoinAmount;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use JsonSerializable;
use RuntimeException;
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
    public CoinAmount $free;

    public function __construct(
        public AccountType $accountType,
        public Coin        $assetCoin,
        float $totalBalance,
        float $availableBalance,
        ?float $freeBalance = null,
    ) {
        $this->total = new CoinAmount($this->assetCoin, $totalBalance);
        $this->available = new CoinAmount($this->assetCoin, $availableBalance);
        if ($this->accountType === AccountType::CONTRACT && $freeBalance === null) {
            throw new RuntimeException(
                sprintf('%s: incorrect usage: `free` balance must be specified', __METHOD__)
            );
        }

        if ($freeBalance !== null) {
            $this->free = new CoinAmount($this->assetCoin, $freeBalance);
        }
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
        if ($this->accountType === AccountType::SPOT) {
            throw new RuntimeException(
                sprintf('incorrect usage of %s for SPOT accountType', __METHOD__)
            );
        }

        return $this->free->value();
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
