<?php

declare(strict_types=1);

namespace App\Domain\Price;

use App\Bot\Domain\ValueObject\Symbol;

class PriceFactory
{
    public function __construct(private readonly Symbol $symbol)
    {
    }

    public function make(float $value): Price
    {
        return Price::float($value);
//        return Price::float($value, $this->symbol);
    }
}