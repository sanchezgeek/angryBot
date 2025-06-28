<?php

declare(strict_types=1);

namespace App\Tests\Stub\TA;

use App\Domain\Trading\Enum\TimeFrame;
use App\Domain\Value\Percent\Percent;
use App\TechnicalAnalysis\Application\Handler\CalcAverageTrueRange\CalcAverageTrueRangeResult;
use App\TechnicalAnalysis\Application\Handler\FindAveragePriceChange\FindAveragePriceChangeResult;
use App\TechnicalAnalysis\Application\Service\TechnicalAnalysisToolsInterface;
use App\TechnicalAnalysis\Domain\Dto\AveragePriceChange;
use App\Trading\Domain\Symbol\SymbolInterface;
use RuntimeException;

class TechnicalAnalysisToolsStub implements TechnicalAnalysisToolsInterface
{
    private array $averagePriceChangePrevResults = [];
    private array $averagePriceChangeResults = [];
    private array $atrResults = [];

    public function __construct(
        public SymbolInterface $symbol,
        public TimeFrame $interval,
    ) {
    }

    public function addAtrResult(
        int $period,
        null|CalcAverageTrueRangeResult $result = null,
        null|Percent|float $percentChange = null,
        null|float $refPrice = null,
        null|float $absoluteChange = null,
    ): self {
        $interval = $this->interval;

        if ($result) {
            $finalResult = $result;
        } else {
            if ($percentChange === null) {
                throw new RuntimeException('$percentChange must be specified in case of no result provided');
            }
            $percent = $percentChange instanceof Percent ? $percentChange : new Percent($percentChange, false);

            if ($absoluteChange === null) {
                if ($refPrice === null) {
                    throw new RuntimeException('$refPrice must be specified');
                }
                $absoluteChange = $percent->of($refPrice);
            }

            $finalResult = new CalcAverageTrueRangeResult(
                new AveragePriceChange($interval, $period, $absoluteChange, $percent)
            );
        }

        $this->atrResults[$period] = $finalResult;

        return $this;
    }

    public function averagePriceChangePrev(int $intervalsCount): FindAveragePriceChangeResult
    {
        throw new RuntimeException('Not implemented yet');
    }

    public function averagePriceChange(int $intervalsCount): FindAveragePriceChangeResult
    {
        throw new RuntimeException('Not implemented yet');
    }

    public function atr(int $intervalsCount): CalcAverageTrueRangeResult
    {
        if (!($result = $this->atrResults[$intervalsCount] ?? null)) {
            throw new RuntimeException(sprintf('Cannot find mocked CalcAverageTrueRangeResult for interval = %d', $intervalsCount));
        }

        return $result;
    }
}
