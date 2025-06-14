<?php

declare(strict_types=1);

namespace App\Chart\Application\Dto;

final readonly class CandleDto
{
    public function __construct(
        public int $time,
        public float $open,
        public float $high,
        public float $low,
        public float $close,
    ) {
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
