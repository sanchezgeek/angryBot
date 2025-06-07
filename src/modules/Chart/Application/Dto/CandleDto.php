<?php

declare(strict_types=1);

namespace App\Chart\Application\Dto;

final readonly class CandleDto
{
    public function __construct(
        private int $time,
        private float $open,
        private float $high,
        private float $low,
        private float $close,
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

    public function priceDiffBetweenHighAndLow(): float
    {
        return abs($this->high - $this->low);
    }
}
