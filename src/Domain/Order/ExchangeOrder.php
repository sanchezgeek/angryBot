<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Price\SymbolPrice;

final class ExchangeOrder // implements OrderInterface
{
    private Symbol $symbol;
    private float $volume;
    private float $providedVolume;
    private SymbolPrice $price;

    public function __construct(Symbol $symbol, float $volume, SymbolPrice|float $price, float $providedVolume = null)
    {
        $this->symbol = $symbol;
        $this->price = $symbol->makePrice(SymbolPrice::toFloat($price));
        // don't add domain logic for check positive volume. Or fix CalcPositionVolumeBasedOnLiquidationPriceHandler first (when get sign for calc parameters on recalculation and swap direction)
        $this->volume = $volume;
        $this->providedVolume = $providedVolume ?? $volume;
    }

    public static function raw(Symbol $symbol, float $volume, SymbolPrice|float $price): self
    {
        return new self($symbol, $volume, $price);
    }

    /**
     * @todo tests
     */
    public static function roundedToMin(Symbol $symbol, float $volume, SymbolPrice|float $price): self
    {
        $providedVolume = $volume;
        $price = SymbolPrice::toFloat($price);

        $minNotionalValue = $symbol->minNotionalOrderValue();
        $value = $volume * $price;
        if ($value < $minNotionalValue) {
            $volumeCalculated = $minNotionalValue / $price;
            $volume = $symbol->roundVolumeUp($volumeCalculated);
        }

        $minQty = $symbol->minOrderQty();
        if (is_int($minQty) && ($volume % $minQty !== 0)) {
            $volume = ceil($volume / $minQty) * $minQty;
        }

        return new self($symbol, $volume, $price, $providedVolume);
    }

    public function getSymbol(): Symbol
    {
        return $this->symbol;
    }

    public function getProvidedVolume(): float
    {
        return $this->providedVolume;
    }

    public function getVolume(): float
    {
        return $this->volume;
    }

    public function getPrice(): SymbolPrice
    {
        return $this->price;
    }
}
