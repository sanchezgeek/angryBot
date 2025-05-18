<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Price\SymbolPrice;

/**
 * @see \App\Tests\Unit\Domain\Order\OrderTest
 */
final readonly class Order
{
    public function __construct(private SymbolPrice $price, private float $volume)
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
}
