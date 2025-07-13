<?php

declare(strict_types=1);

namespace App\Tests\Stub\TA;

use App\Domain\Position\ValueObject\Side;
use App\Domain\Trading\Enum\PredefinedStopLengthSelector;
use App\Domain\Trading\Enum\TimeFrame;
use App\Domain\Value\Percent\Percent;
use App\TechnicalAnalysis\Domain\Dto\AveragePriceChange;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use RuntimeException;

class TradingParametersProviderStub implements TradingParametersProviderInterface
{
    public array $regularPredefinedStopLengthResults = [];
    private array $regularOppositeBuyOrderLengthResults = [];

    public function addRegularOppositeBuyOrderLengthResult(
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

    private function regularOppositeBuyOrderLengthResultsKey(
        SymbolInterface $symbol,
        PredefinedStopLengthSelector $sourceStopLength,
        TimeFrame $timeframe,
        int $period
    ): string {
        return sprintf('regularOppositeBuyOrderLengthResult_%s_%s_%s_%s', $symbol->name(), $sourceStopLength->value, $timeframe->value, $period);
    }

    public function addRegularPredefinedStopLengthResult(
        Percent $percentResult,
        SymbolInterface $symbol,
        PredefinedStopLengthSelector $sourceStopLength,
        TimeFrame $timeframe = TradingParametersProviderInterface::LONG_ATR_TIMEFRAME,
        int $period = TradingParametersProviderInterface::ATR_PERIOD_FOR_ORDERS,
    ): self {
        $key = self::regularPredefinedStopLengthResultKey($symbol, $sourceStopLength, $timeframe, $period);

        $this->regularPredefinedStopLengthResults[$key] = $percentResult;

        return $this;
    }

    private function regularPredefinedStopLengthResultKey(
        SymbolInterface $symbol,
        PredefinedStopLengthSelector $predefinedStopLength,
        TimeFrame $timeframe,
        int $period
    ): string {
        return sprintf('regularPredefinedStopLengthResultKey_%s_%s_%s_%s', $symbol->name(), $predefinedStopLength->value, $timeframe->value, $period);
    }

    public function safeLiquidationPriceDelta(
        SymbolInterface $symbol,
        Side $side,
        float $refPrice
    ): float {
        throw new RuntimeException('Not implemented yet');
    }

    public function significantPriceChange(
        SymbolInterface $symbol,
        float $passedPartOfDay
    ): Percent {
        throw new RuntimeException('Not implemented yet');
    }

    public function regularPredefinedStopLength(
        SymbolInterface $symbol,
        PredefinedStopLengthSelector $predefinedStopLength,
        TimeFrame $timeframe = self::LONG_ATR_TIMEFRAME,
        int $period = self::ATR_PERIOD_FOR_ORDERS,
    ): Percent {
//        var_dump('111111');
        $key = self::regularPredefinedStopLengthResultKey($symbol, $predefinedStopLength, $timeframe, $period);
        if (!isset($this->regularPredefinedStopLengthResults[$key])) {
            throw new RuntimeException(sprintf('Cannot find mocked regularPredefinedStopLengthResults result for %s', $key));
        }

        return $this->regularPredefinedStopLengthResults[$key];
    }

    public function regularOppositeBuyOrderLength(
        SymbolInterface $symbol,
        PredefinedStopLengthSelector $sourceStopLength,
        TimeFrame $timeframe = self::LONG_ATR_TIMEFRAME,
        int $period = self::ATR_PERIOD_FOR_ORDERS,
    ): Percent {
        $key = self::regularOppositeBuyOrderLengthResultsKey($symbol, $sourceStopLength, $timeframe, $period);
        if (!isset($this->regularOppositeBuyOrderLengthResults[$key])) {
            throw new RuntimeException(sprintf('Cannot find mocked regularOppositeBuyOrderLengthResults result for %s', $key));
        }

        return $this->regularOppositeBuyOrderLengthResults[$key];
    }

    public function standardAtrForOrdersLength(
        SymbolInterface $symbol,
        TimeFrame $timeframe = self::LONG_ATR_TIMEFRAME,
        int $period = self::ATR_PERIOD_FOR_ORDERS
    ): AveragePriceChange {
        throw new RuntimeException('Not implemented yet');
    }
}
