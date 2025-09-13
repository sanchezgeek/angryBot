<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Contract;

use App\Domain\Trading\Enum\TimeFrame;
use App\TechnicalAnalysis\Application\Service\TechnicalAnalysisToolsInterface;
use App\Trading\Domain\Symbol\SymbolInterface;

interface TAToolsProviderInterface
{
    public function create(SymbolInterface $symbol, TimeFrame $interval): TechnicalAnalysisToolsInterface;
}
