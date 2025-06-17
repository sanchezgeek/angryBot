<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Domain\Dto;

use App\Domain\Candle\Enum\CandleIntervalEnum;
use App\Domain\Value\Percent\Percent;
use JsonSerializable;
use Stringable;

final class TAPriceChange implements JsonSerializable, Stringable
{
    public function __construct(
        public CandleIntervalEnum $onInterval,
        public Percent $percentChange,
        public float $absoluteChange,
        public float $refPrice
    ) {
    }

    public function __toString(): string
    {
        return sprintf('%s (%s) change [from %s] on `%s` interval', $this->absoluteChange, $this->percentChange, $this->refPrice, $this->onInterval->value);
    }

    public function jsonSerialize(): mixed
    {
        return [
            'onInterval' => $this->onInterval->value,
            'refPrice' => $this->refPrice,
            'percentChange' => $this->percentChange->value(),
            'absoluteChange' => $this->absoluteChange,
        ];
    }
}
