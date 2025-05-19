<?php

declare(strict_types=1);

namespace App\Domain\Price\Helper;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Price\SymbolPrice;

readonly class PriceFormatter
{
    public function __construct(private Symbol $symbol)
    {
    }

    public function format(SymbolPrice|float $price): string
    {
        $price = SymbolPrice::toFloat($price);

        $pricePrecision = $this->symbol->pricePrecision();

        return sprintf('%.' . $pricePrecision . 'f', $price);
    }
}
