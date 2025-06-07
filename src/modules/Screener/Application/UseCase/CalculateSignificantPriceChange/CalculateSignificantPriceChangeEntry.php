<?php

declare(strict_types=1);

namespace App\Screener\Application\UseCase\CalculateSignificantPriceChange;

use App\Domain\Candle\Enum\CandleIntervalEnum;
use App\Trading\Domain\Symbol\SymbolInterface;

final class CalculateSignificantPriceChangeEntry
{
    public function __construct(
        public SymbolInterface $symbol,
        public CandleIntervalEnum $averageOnInterval,
        public int $intervalsCount
    ) {
    }
}
