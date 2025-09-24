<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Service;

use App\Domain\Trading\Enum\TimeFrame;
use App\TechnicalAnalysis\Application\Contract\Query\GetInstrumentAgeResult;
use App\TechnicalAnalysis\Application\Handler\CalcAverageTrueRange\CalcAverageTrueRangeResult;
use App\TechnicalAnalysis\Application\Handler\FindAveragePriceChange\FindAveragePriceChangeResult;
use App\TechnicalAnalysis\Domain\Dto\HighLow\HighLowPrices;
use App\Trading\Domain\Symbol\SymbolInterface;

/**
 * @property-read SymbolInterface $symbol
 * @property-read TimeFrame $interval
 */
interface TechnicalAnalysisToolsInterface
{
    public function averagePriceChangePrev(int $intervalsCount): FindAveragePriceChangeResult;
    public function averagePriceChange(int $intervalsCount): FindAveragePriceChangeResult;
    public function atr(int $intervalsCount): CalcAverageTrueRangeResult;
    public function highLowPrices(): HighLowPrices;
    public function instrumentAge(): GetInstrumentAgeResult;
}
