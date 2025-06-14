<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Domain\Dto;

use App\Domain\Candle\Enum\CandleIntervalEnum;
use App\Domain\Value\Percent\Percent;
use JsonSerializable;
use Stringable;

final readonly class AveragePriceChange implements JsonSerializable, Stringable
{
    public function __construct(
        public CandleIntervalEnum $onInterval,
        public int $intervalsCount,
        public Percent $pct,
        public float $absolute,
    ) {
    }

    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->absolute, $this->pct);
    }

    public function jsonSerialize(): string
    {
        return (string)$this;
    }
}
