<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Service;

use App\Domain\Candle\Enum\CandleIntervalEnum;
use App\TechnicalAnalysis\Application\Contract\CalcAverageTrueRangeHandlerInterface;
use App\TechnicalAnalysis\Application\Contract\FindAveragePriceChangeHandlerInterface;
use App\TechnicalAnalysis\Application\Contract\Query\CalcAverageTrueRange;
use App\TechnicalAnalysis\Application\Contract\Query\FindAveragePriceChange;
use App\TechnicalAnalysis\Application\Handler\CalcAverageTrueRange\CalcAverageTrueRangeResult;
use App\TechnicalAnalysis\Application\Handler\FindAveragePriceChange\FindAveragePriceChangeResult;
use App\Trading\Domain\Symbol\SymbolInterface;
use InvalidArgumentException;

final class TechnicalAnalysisTools
{
    public ?CandleIntervalEnum $candleInterval = null;

    public function __construct(
        public readonly SymbolInterface $symbol,
        private readonly FindAveragePriceChangeHandlerInterface $findAveragePriceChangeHandler,
        private readonly CalcAverageTrueRangeHandlerInterface $calcAverageTrueRangeHandler,
    ) {
    }

    public function withInterval(CandleIntervalEnum $candleInterval): self
    {
        $this->candleInterval = $candleInterval;
        return $this;
    }

    public function averagePriceChangePrev(int $intervalsCount): FindAveragePriceChangeResult
    {
        $this->checkIntervalSelected();

        return $this->findAveragePriceChangeHandler->handle(
            FindAveragePriceChange::previousToCurrentInterval($this->symbol, $this->candleInterval, $intervalsCount)
        );
    }

    public function averagePriceChange(int $intervalsCount): FindAveragePriceChangeResult
    {
        $this->checkIntervalSelected();

        return $this->findAveragePriceChangeHandler->handle(
            FindAveragePriceChange::includeCurrentInterval($this->symbol, $this->candleInterval, $intervalsCount)
        );
    }

    public function atr(int $intervalsCount): CalcAverageTrueRangeResult
    {
        $this->checkIntervalSelected();

        return $this->calcAverageTrueRangeHandler->handle(
            new CalcAverageTrueRange($this->symbol, $this->candleInterval, $intervalsCount)
        );
    }

    private function checkIntervalSelected(): void
    {
        if ($this->candleInterval === null) {
            throw new InvalidArgumentException('Interval must be specified');
        }
    }
}
