<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Contract\Query;

use App\Domain\Trading\Enum\TimeFrame;
use App\Trading\Domain\Symbol\SymbolInterface;

final class CalcAverageTrueRange
{
    public function __construct(
        public SymbolInterface $symbol,
        public TimeFrame $timeframe,
        public int $period,
    ) {
        assert($this->period >= 2, 'For ATR calculation period must be >= 2');
    }
}
