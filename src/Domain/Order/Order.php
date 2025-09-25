<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Price\SymbolPrice;

/**
 * @see \App\Tests\Unit\Domain\Order\OrderTest
 */
final readonly class Order
{
    public function __construct(private SymbolPrice $price, private float $volume, private array $context = [])
    {
    }

    public function price(): SymbolPrice
    {
        return $this->price;
    }

    public function volume(): float
    {
        return $this->volume;
    }

    public function context(): array
    {
        return $this->context;
    }

    public function replacePrice(SymbolPrice $price): self
    {
        return new self(
            $price,
            $this->volume,
            $this->context
        );
    }
}
