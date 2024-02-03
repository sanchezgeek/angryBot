<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Price\Price;
use DomainException;

use function floor;

final class ExchangeOrder // implements OrderInterface
{
    private Symbol $symbol;
    private float $volume;
    private Price $price;

    public function __construct(Symbol $symbol, float $volume, Price $price)
    {
        $this->symbol = $symbol;
        $this->volume = $volume;
        $this->price = $price;
    }

    public function getSymbol(): Symbol
    {
        return $this->symbol;
    }

    public function getVolume(): float
    {
        return $this->volume;
    }

    public function getPrice(): Price
    {
        return $this->price;
    }

    /**
     * @throws DomainException
     */
    private function validateAmount(): void
    {
        if ($this->volume - floor($this->volume) !== 0) {
            throw new DomainException();
        }
    }
}
