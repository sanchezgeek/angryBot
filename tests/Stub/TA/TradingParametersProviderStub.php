<?php

declare(strict_types=1);

namespace App\Tests\Stub\TA;

use App\Domain\Position\ValueObject\Side;
use App\Domain\Trading\Enum\PredefinedStopLengthSelector;
use App\Domain\Trading\Enum\TimeFrame;
use App\Domain\Value\Percent\Percent;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use RuntimeException;

class TradingParametersProviderStub implements TradingParametersProviderInterface
{
    private array $regularOppositeBuyOrderLengthResults = [];

    public function addRegularOppositeBuyOrderLengthResults(
        SymbolInterface $symbol,
        PredefinedStopLengthSelector $sourceStopLength,
        TimeFrame $timeframe,
        int $period,
        Percent $percentResult,
    ): self {
        $key = self::regularOppositeBuyOrderLengthResultsKey($symbol, $sourceStopLength, $timeframe, $period);

        $this->regularOppositeBuyOrderLengthResults[$key] = $percentResult;

        return $this;
    }

    private function regularOppositeBuyOrderLengthResultsKey(SymbolInterface $symbol,
        PredefinedStopLengthSelector $sourceStopLength,
        TimeFrame $timeframe,
        int $period,): string
    {
        return sprintf('%s_%s_%s_%s', $symbol->name(), $sourceStopLength->value, $timeframe->value, $period);
    }

    public function safeLiquidationPriceDelta(
        SymbolInterface $symbol,
        Side $side,
        float $refPrice
    ): float {
        throw new RuntimeException('Not implemented yet');
    }

    public function significantPriceChangePercent(
        SymbolInterface $symbol,
        float $passedPartOfDay
    ): Percent {
        throw new RuntimeException('Not implemented yet');
    }

    public function regularPredefinedStopLength(
        SymbolInterface $symbol,
        PredefinedStopLengthSelector $predefinedStopLength,
        TimeFrame $timeframe,
        int $period
    ): Percent {
        throw new RuntimeException('Not implemented yet');
    }

    public function regularOppositeBuyOrderLength(
        SymbolInterface $symbol,
        PredefinedStopLengthSelector $sourceStopLength,
        TimeFrame $timeframe,
        int $period
    ): Percent {
        $key = self::regularOppositeBuyOrderLengthResultsKey($symbol, $sourceStopLength, $timeframe, $period);
        if (!isset($this->regularOppositeBuyOrderLengthResults[$key])) {
            throw new RuntimeException(sprintf('Cannot find mocked result for %s', $key));
        }

        return $this->regularOppositeBuyOrderLengthResults[$key];
    }
}
