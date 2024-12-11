<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Price\Price;
use DomainException;

use function floor;
use function var_dump;

final class ExchangeOrder // implements OrderInterface
{
    private Symbol $symbol;
    private float $volume;
    private float $providedVolume;
    private Price $price;

    public function __construct(Symbol $symbol, float $volume, Price|float $price, bool $roundValueToMinNotional = false)
    {
        $this->symbol = $symbol;
        $this->price = $symbol->makePrice(Price::toFloat($price));
        $this->providedVolume = $volume;

        $value = $volume * $this->price->value();
        if ($roundValueToMinNotional && $value < ($minNotionalValue = $symbol->minNotionalOrderValue())) {
            $volumeCalculated = $minNotionalValue / $this->price->value();
            $volume = $symbol->roundVolume($volumeCalculated);
        }

        $this->volume = $volume;
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
