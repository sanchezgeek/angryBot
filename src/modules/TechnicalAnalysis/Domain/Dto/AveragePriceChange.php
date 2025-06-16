<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Domain\Dto;

use App\Domain\Candle\Enum\CandleIntervalEnum;
use App\Domain\Price\SymbolPrice;
use App\Domain\Value\Percent\Percent;
use JsonSerializable;
use Stringable;

final readonly class AveragePriceChange implements JsonSerializable, Stringable
{
    public function __construct(
        public CandleIntervalEnum $onInterval,
        public int $intervalsCount,
        public Percent $percent,
        public float $absolute,
    ) {
    }

    public function of(float|SymbolPrice $price): float
    {
        return $this->percent->of($price instanceof SymbolPrice ? $price->value() : $price);
    }

    public function multiply(float $multiplier): self
    {
        return new self(
            $this->onInterval,
            $this->intervalsCount,
            new Percent($this->percent->value() * $multiplier, false),
            $this->absolute * $multiplier
        );
    }

    public function divide(float $divider): self
    {
        return new self(
            $this->onInterval,
            $this->intervalsCount,
            new Percent($this->percent->value() / $divider, false),
            $this->absolute / $divider
        );
    }

    public function __toString(): string
    {
        return sprintf('%s (%s) [average`%s`priceChange on last %d intervals]', $this->absolute, $this->percent, $this->onInterval->value, $this->intervalsCount);
    }

    public function jsonSerialize(): string
    {
        return (string)$this;
    }
}
