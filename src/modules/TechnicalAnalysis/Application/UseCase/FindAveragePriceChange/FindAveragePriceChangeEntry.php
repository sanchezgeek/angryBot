<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\UseCase\FindAveragePriceChange;

use App\Domain\Candle\Enum\CandleIntervalEnum;
use App\Trading\Domain\Symbol\SymbolInterface;

final class FindAveragePriceChangeEntry
{
    public function __construct(
        public SymbolInterface $symbol,
        public CandleIntervalEnum $averageOnInterval,
        public int $intervalsCount
    ) {
    }
}
