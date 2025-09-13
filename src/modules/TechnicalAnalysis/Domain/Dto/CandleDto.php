<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Domain\Dto;

use App\Domain\Trading\Enum\TimeFrame;
use JsonSerializable;

final readonly class CandleDto implements JsonSerializable
{
    public function __construct(
        public TimeFrame $interval,
        public int $time,
        public float $open,
        public float $high,
        public float $low,
        public float $close,
    ) {
    }

    public static function fromArray(TimeFrame $interval, array $data): self
    {
        return new self(
            $interval,
            $data['time'],
            $data['open'],
            $data['high'],
            $data['low'],
            $data['close'],
        );
    }

    public function toArray(): array
    {
        return [
            'interval' => $this->interval->value,
            'time' => $this->time,
            'open' => $this->open,
            'high' => $this->high,
            'low' => $this->low,
            'close' => $this->close,
        ];
    }

    public function jsonSerialize(): array
    {
        return [
            'time' => $this->time,
            'utcDatetime' => date('Y-m-d H:i:s', $this->time),
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
