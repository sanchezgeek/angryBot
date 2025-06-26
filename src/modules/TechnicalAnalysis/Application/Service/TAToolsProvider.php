<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Service;

use App\Domain\Candle\Enum\CandleIntervalEnum;
use App\TechnicalAnalysis\Application\Contract\CalcAverageTrueRangeHandlerInterface;
use App\TechnicalAnalysis\Application\Contract\FindAveragePriceChangeHandlerInterface;
use App\TechnicalAnalysis\Application\Contract\TAToolsProviderInterface;
use App\Trading\Domain\Symbol\SymbolInterface;

final readonly class TAToolsProvider implements TAToolsProviderInterface
{
    public function __construct(
        private FindAveragePriceChangeHandlerInterface $findAveragePriceChangeHandler,
        private CalcAverageTrueRangeHandlerInterface $calcAverageTrueRangeHandler,
    ) {
    }

    public function create(SymbolInterface $symbol, CandleIntervalEnum $interval): TechnicalAnalysisTools
    {
        // @todo tests
        return new TechnicalAnalysisTools(
            $symbol,
            $interval,
            $this->findAveragePriceChangeHandler,
            $this->calcAverageTrueRangeHandler,
        );
    }
}
