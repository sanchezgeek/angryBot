<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Helper;

use App\TechnicalAnalysis\Domain\Dto\CandleDto;

final class TraderInput
{
    private readonly array $candles;

    public function __construct(private readonly int $multiplier, CandleDto ...$candlesDto)
    {
        $this->candles = $candlesDto;
    }

    public ?array $lowPrices = null {
        get {
            if ($this->lowPrices !== null) {
                return $this->lowPrices;
            }

            return $this->lowPrices = array_map(fn(CandleDto $candle) => $candle->low * $this->multiplier, $this->candles);
        }
    }

    public ?array $highPrices = null {
        get {
            if ($this->highPrices !== null) {
                return $this->highPrices;
            }

            return $this->highPrices = array_map(fn(CandleDto $candle) => $candle->high * $this->multiplier, $this->candles);
        }
    }

    public ?array $closePrices = null {
        get {
            if ($this->closePrices !== null) {
                return $this->closePrices;
            }

            return $this->closePrices = array_map(fn(CandleDto $candle) => $candle->close * $this->multiplier, $this->candles);
        }
    }

    public ?array $openPrices = null {
        get {
            if ($this->openPrices !== null) {
                return $this->openPrices;
            }

            return $this->openPrices = array_map(fn(CandleDto $candle) => $candle->open * $this->multiplier, $this->candles);
        }
    }
}
