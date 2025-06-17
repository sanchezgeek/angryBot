<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Domain\Dto;

use App\Domain\Candle\Enum\CandleIntervalEnum;
use App\Domain\Value\Percent\Percent;
use JsonSerializable;
use Stringable;

final class PriceChange implements JsonSerializable, Stringable
{
    public function __construct(
        public CandleIntervalEnum $interval,
        public float $absoluteChange,
        public Percent $percentChange,
        public float $refPrice
    ) {
    }

    public function __toString(): string
    {
        return sprintf('%s [%s, refPrice=%s] (`%s`priceChange)', $this->absoluteChange, $this->percentChange, $this->refPrice, $this->interval->value);
    }

    public function jsonSerialize(): mixed
    {
        return [
            'interval' => $this->interval->value,
            'absoluteChange' => $this->absoluteChange,
            'percentChange' => $this->percentChange->value(),
            'refPrice' => $this->refPrice,
        ];
    }
}
