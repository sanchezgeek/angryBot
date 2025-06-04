<?php

declare(strict_types=1);

namespace App\Domain\Price\Helper;

use App\Domain\Price\SymbolPrice;
use App\Trading\Domain\Symbol\SymbolInterface;

readonly class PriceFormatter
{
    public function __construct(private SymbolInterface $symbol)
    {
    }

    public function format(SymbolPrice|float $price): string
    {
        $price = SymbolPrice::toFloat($price);

        $pricePrecision = $this->symbol->pricePrecision();

        return sprintf('%.' . $pricePrecision . 'f', $price);
    }
}
