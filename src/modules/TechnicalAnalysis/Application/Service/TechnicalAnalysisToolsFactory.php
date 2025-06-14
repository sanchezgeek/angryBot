<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Service;

use App\Domain\Candle\Enum\CandleIntervalEnum;
use App\TechnicalAnalysis\Application\Contract\FindAveragePriceChangeHandlerInterface;
use App\Trading\Domain\Symbol\SymbolInterface;

final readonly class TechnicalAnalysisToolsFactory
{
    public function __construct(
        private FindAveragePriceChangeHandlerInterface $findAveragePriceChangeHandler
    ) {
    }

    public function create(SymbolInterface $symbol, ?CandleIntervalEnum $candleIntervalEnum = null): TechnicalAnalysisTools
    {
        $tools = new TechnicalAnalysisTools(
            $symbol,
            $this->findAveragePriceChangeHandler
        );

        if ($candleIntervalEnum) {
            $tools->withInterval($candleIntervalEnum);
        }

        return $tools;
    }
}
