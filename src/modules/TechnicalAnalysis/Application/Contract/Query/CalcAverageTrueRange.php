<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Contract\Query;

use App\Domain\Candle\Enum\CandleIntervalEnum;
use App\Trading\Domain\Symbol\SymbolInterface;

final class CalcAverageTrueRange
{
    public function __construct(
        public SymbolInterface $symbol,
        public CandleIntervalEnum $interval,
        public int $period,
    ) {
    }
}
