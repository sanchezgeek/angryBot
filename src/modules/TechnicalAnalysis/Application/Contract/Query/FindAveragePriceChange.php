<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Contract\Query;

use App\Domain\Trading\Enum\TimeFrame;
use App\Trading\Domain\Symbol\SymbolInterface;

final class FindAveragePriceChange
{
    private function __construct(
        public SymbolInterface $symbol,
        public TimeFrame $averageOnInterval,
        public int $intervalsCount,
        public bool $useCurrentUnfinishedIntervalForCalc,
    ) {
    }

    public static function previousToCurrentInterval(
        SymbolInterface $symbol,
        TimeFrame $averageOnInterval,
        int $intervalsCount,
    ): self {
        return new self($symbol, $averageOnInterval, $intervalsCount, false);
    }

    public static function includeCurrentInterval(
        SymbolInterface $symbol,
        TimeFrame $averageOnInterval,
        int $intervalsCount,
    ): self {
        return new self($symbol, $averageOnInterval, $intervalsCount, true);
    }
}
