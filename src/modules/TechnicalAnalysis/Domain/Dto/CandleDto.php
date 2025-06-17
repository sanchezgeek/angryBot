<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Domain\Dto;

use App\Domain\Candle\Enum\CandleIntervalEnum;

final readonly class CandleDto
{
    public function __construct(
        public CandleIntervalEnum $interval,
        public int $time,
        public float $open,
        public float $high,
        public float $low,
        public float $close,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            CandleIntervalEnum::from($data['interval']),
            $data['time'],
            $data['open'],
            $data['high'],
            $data['low'],
            $data['close'],
        );
    }

    public function jsonSerialize(): mixed
    {
        return [
            'time' => $this->time,
            'open' => $this->open,
            'high' => $this->high,
            'low' => $this->low,
            'close' => $this->close,
        ];
    }

    public function highLowDiff(): float
    {
        return abs($this->high - $this->low);
    }
}
