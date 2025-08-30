<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Service;

use App\Domain\Trading\Enum\TimeFrame;
use App\TechnicalAnalysis\Application\Contract\CalcAverageTrueRangeHandlerInterface;
use App\TechnicalAnalysis\Application\Contract\FindAveragePriceChangeHandlerInterface;
use App\TechnicalAnalysis\Application\Contract\FindHighLowPricesHandlerInterface;
use App\TechnicalAnalysis\Application\Contract\Query\CalcAverageTrueRange;
use App\TechnicalAnalysis\Application\Contract\Query\FindAveragePriceChange;
use App\TechnicalAnalysis\Application\Contract\Query\FindHighLowPrices;
use App\TechnicalAnalysis\Application\Contract\Query\FindHighLowPricesResult;
use App\TechnicalAnalysis\Application\Handler\CalcAverageTrueRange\CalcAverageTrueRangeResult;
use App\TechnicalAnalysis\Application\Handler\FindAveragePriceChange\FindAveragePriceChangeResult;
use App\Trading\Domain\Symbol\SymbolInterface;
use InvalidArgumentException;

final readonly class TechnicalAnalysisTools implements TechnicalAnalysisToolsInterface
{
    public function __construct(
        private FindAveragePriceChangeHandlerInterface $findAveragePriceChangeHandler,
        private CalcAverageTrueRangeHandlerInterface $calcAverageTrueRangeHandler,
        private FindHighLowPricesHandlerInterface $findHighLowPricesHandler,
        public SymbolInterface $symbol,
        public ?TimeFrame $candleInterval = null,
    ) {
    }

    public function averagePriceChangePrev(int $intervalsCount): FindAveragePriceChangeResult
    {
        $this->checkIntervalSelected('averagePriceChangePrev');

        return $this->findAveragePriceChangeHandler->handle(
            FindAveragePriceChange::previousToCurrentInterval($this->symbol, $this->candleInterval, $intervalsCount)
        );
    }

    public function averagePriceChange(int $intervalsCount): FindAveragePriceChangeResult
    {
        $this->checkIntervalSelected('averagePriceChange');

        return $this->findAveragePriceChangeHandler->handle(
            FindAveragePriceChange::includeCurrentInterval($this->symbol, $this->candleInterval, $intervalsCount)
        );
    }

    public function atr(int $intervalsCount): CalcAverageTrueRangeResult
    {
        $this->checkIntervalSelected('atr');

        return $this->calcAverageTrueRangeHandler->handle(
            new CalcAverageTrueRange($this->symbol, $this->candleInterval, $intervalsCount)
        );
    }

    public function highLowPrices(): FindHighLowPricesResult
    {
        return $this->findHighLowPricesHandler->handle(
            new FindHighLowPrices($this->symbol)
        );
    }

    private function checkIntervalSelected(string $forCalc): void
    {
        if ($this->candleInterval === null) {
            throw new InvalidArgumentException(sprintf('Interval must be specified (for calc "%s")', $forCalc));
        }
    }
}
