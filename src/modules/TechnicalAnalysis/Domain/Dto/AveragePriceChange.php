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
        public CandleIntervalEnum $interval,
        public int $period,
        public float $absoluteChange,
        // @todo | of what???
        public Percent $percentChange,
    ) {
    }

    public function of(float|SymbolPrice $price): float
    {
        return $this->percentChange->of($price instanceof SymbolPrice ? $price->value() : $price);
    }

    public function multiply(float $multiplier): self
    {
        return new self(
            $this->interval,
            $this->period,
            $this->absoluteChange * $multiplier,
            new Percent($this->percentChange->value() * $multiplier, false),
        );
    }

    public function divide(float $divider): self
    {
        return new self(
            $this->interval,
            $this->period,
            $this->absoluteChange / $divider,
            new Percent($this->percentChange->value() / $divider, false),
        );
    }

    public function __toString(): string
    {
        return sprintf('%s (%s) [average`%s`priceChange on last %d intervals]', $this->absoluteChange, $this->percentChange, $this->interval->value, $this->period);
    }

    public function jsonSerialize(): string
    {
        return (string)$this;
    }
}
