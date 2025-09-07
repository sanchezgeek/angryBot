<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Service;

use App\Domain\Trading\Enum\TimeFrame;
use App\TechnicalAnalysis\Application\Contract\CalcAverageTrueRangeHandlerInterface;
use App\TechnicalAnalysis\Application\Contract\FindAveragePriceChangeHandlerInterface;
use App\TechnicalAnalysis\Application\Contract\FindHighLowPricesHandlerInterface;
use App\TechnicalAnalysis\Application\Contract\Query\GetInstrumentAgeHandlerInterface;
use App\TechnicalAnalysis\Application\Contract\TAToolsProviderInterface;
use App\Trading\Domain\Symbol\SymbolInterface;

final readonly class TAToolsProvider implements TAToolsProviderInterface
{
    public function __construct(
        private FindAveragePriceChangeHandlerInterface $findAveragePriceChangeHandler,
        private CalcAverageTrueRangeHandlerInterface $calcAverageTrueRangeHandler,
        private FindHighLowPricesHandlerInterface $findHighLowPricesHandler,
        private GetInstrumentAgeHandlerInterface $getInstrumentAgeHandler,
    ) {
    }

    public function create(SymbolInterface $symbol, ?TimeFrame $interval = null): TechnicalAnalysisTools
    {
        return new TechnicalAnalysisTools(
            $this->findAveragePriceChangeHandler,
            $this->calcAverageTrueRangeHandler,
            $this->findHighLowPricesHandler,
            $this->getInstrumentAgeHandler,
            $symbol,
            $interval,
        );
    }
}
