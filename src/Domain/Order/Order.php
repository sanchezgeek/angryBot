<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Price\Price;

/**
 * @see \App\Tests\Unit\Domain\Order\OrderTest
 */
final readonly class Order
{
    public function __construct(private Price $price, private float $volume)
    {
    }

    public function price(): Price
    {
        return $this->price;
    }

    public function volume(): float
    {
        return $this->volume;
    }
}
