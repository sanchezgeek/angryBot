<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\MarketStructure\V1;

final class ZigZagPoint
{
    public function __construct(
        public int $candleIndex,
        public float $price,
        public string $type
    ) {}

    public function getCandleIndex(): int
    {
        return $this->candleIndex;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function getType(): string
    {
        return $this->type;
    }
}
