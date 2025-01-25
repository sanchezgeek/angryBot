<?php

declare(strict_types=1);

namespace App\Domain\Price;

use App\Bot\Domain\ValueObject\Symbol;

readonly class PriceFactory
{
    public function __construct(private Symbol $symbol)
    {
    }

    public function make(float $value): Price
    {
        return Price::float($value, $this->symbol->pricePrecision());
    }
}