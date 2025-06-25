<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Contract;

use App\Domain\Candle\Enum\CandleIntervalEnum;
use App\TechnicalAnalysis\Application\Service\TechnicalAnalysisTools;
use App\Trading\Domain\Symbol\SymbolInterface;

interface TAToolsProviderInterface
{
    public function create(SymbolInterface $symbol, ?CandleIntervalEnum $candleIntervalEnum = null): TechnicalAnalysisTools;
}
