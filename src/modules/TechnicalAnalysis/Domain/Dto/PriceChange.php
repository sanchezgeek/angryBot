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
        public CandleIntervalEnum $timeframe,
        public float $absolute,
        public Percent $percent,
        public float $refPrice
    ) {
    }

    public function __toString(): string
    {
        return sprintf('%s [%s, refPrice=%s] (`%s`priceChange)', $this->absolute, $this->percent, $this->refPrice, $this->timeframe->value);
    }

    public function jsonSerialize(): mixed
    {
        return [
            'interval' => $this->timeframe->value,
            'absoluteChange' => $this->absolute,
            'percentChange' => $this->percent->value(),
            'refPrice' => $this->refPrice,
        ];
    }

    /**
     * @todo YAGNI
     */
    public function multiply(float $multiplier): self
    {
        return new self(
            $this->timeframe,
            $this->absolute * $multiplier,
            new Percent($this->percent->value() * $multiplier, false),
            $this->refPrice,
        );
    }

    /**
     * @todo YAGNI
     */
    public static function fromPercentAndRef(CandleIntervalEnum $timeFrame, Percent $percentChange, float $refPrice): self
    {
        return new self(
            $timeFrame,
            $percentChange->of($refPrice),
            $percentChange,
            $refPrice
        );
    }

    /**
     * @todo YAGNI
     */
    public static function fromAveragePriceChange(AveragePriceChange $averagePriceChange): self
    {
        return new self(
            $averagePriceChange->interval,
            $averagePriceChange->absoluteChange,
            $averagePriceChange->percentChange,
            $averagePriceChange->refPrice
        );
    }
}
