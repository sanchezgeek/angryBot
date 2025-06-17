<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Handler\CalcAverageTrueRange;

use App\TechnicalAnalysis\Domain\Dto\AveragePriceChange;
use Stringable;

final readonly class CalcAverageTrueRangeResult implements Stringable
{
    public function __construct(
        public AveragePriceChange $atr
        // методы для получения TR (byIntervalsAgo(), today, yesterday, ...)
        // + у dtoшки должна быть возвожность проверить флаг isAnomaly()
    ) {
    }

    public function __toString(): string
    {
        return (string)$this->atr;
    }
}
