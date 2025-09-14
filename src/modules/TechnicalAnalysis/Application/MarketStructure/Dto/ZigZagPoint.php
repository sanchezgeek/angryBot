<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\MarketStructure\Dto;

use JsonSerializable;

final class ZigZagPoint implements JsonSerializable
{
    public const PEAK = 'peak';
    public const TROUGH = 'trough';

    public function __construct(
        private int $candleIndex,
        private float $price,
        private string $type,
        private int $time,
    ) {
    }

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

    public function getUtcDatetime(): string
    {
        return date('Y-m-d H:i:s', $this->time);
    }

    public function jsonSerialize(): array
    {
        return [
            'time' => $this->time,
            'type' => $this->type,
            'price' => $this->price,
//            'utcDatetime' => $this->getUtcDatetime(),
        ];
    }
}
