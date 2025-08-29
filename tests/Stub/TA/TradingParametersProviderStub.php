<?php

declare(strict_types=1);

namespace App\Tests\Stub\TA;

use App\Domain\Position\ValueObject\Side;
use App\Domain\Trading\Enum\PriceDistanceSelector;
use App\Domain\Trading\Enum\TimeFrame;
use App\Domain\Trading\Enum\TradingStyle;
use App\Domain\Value\Percent\Percent;
use App\TechnicalAnalysis\Domain\Dto\AveragePriceChange;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use RuntimeException;

class TradingParametersProviderStub implements TradingParametersProviderInterface
{
    public array $stopLengthResults = [];
    private array $oppositeBuyLengthResults = [];

    public function tradingStyle(SymbolInterface $symbol, Side $side): TradingStyle
    {
        return TradingStyle::Conservative;
    }

    public function addOppositeBuyLengthResult(
        SymbolInterface $symbol,
        PriceDistanceSelector $distanceSelector,
        TimeFrame $timeframe,
        int $period,
        Percent $percentResult,
    ): self {
        $key = self::_oppositeBuyLengthResultsKey($symbol, $distanceSelector, $timeframe, $period);

        $this->oppositeBuyLengthResults[$key] = $percentResult;

        return $this;
    }

    private function _oppositeBuyLengthResultsKey(
        SymbolInterface $symbol,
        PriceDistanceSelector $distanceSelector,
        TimeFrame $timeframe,
        int $period
    ): string {
        return sprintf('regularOppositeBuyOrderLengthResult_%s_%s_%s_%s', $symbol->name(), $distanceSelector->value, $timeframe->value, $period);
    }

    public function addStopLengthResult(
        Percent $percentResult,
        SymbolInterface $symbol,
        PriceDistanceSelector $distanceSelector,
        TimeFrame $timeframe = TradingParametersProviderInterface::LONG_ATR_TIMEFRAME,
        int $period = TradingParametersProviderInterface::ATR_PERIOD_FOR_ORDERS,
    ): self {
        $key = self::_stopLengthResultKey($symbol, $distanceSelector, $timeframe, $period);

        $this->stopLengthResults[$key] = $percentResult;

        return $this;
    }

    private function _stopLengthResultKey(
        SymbolInterface $symbol,
        PriceDistanceSelector $distanceSelector,
        TimeFrame $timeframe,
        int $period
    ): string {
        return sprintf('stopLengthResultKey_%s_%s_%s_%s', $symbol->name(), $distanceSelector->value, $timeframe->value, $period);
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

    public function stopLength(
        SymbolInterface $symbol,
        PriceDistanceSelector $distanceSelector,
        TimeFrame $timeframe = self::LONG_ATR_TIMEFRAME,
        int $period = self::ATR_PERIOD_FOR_ORDERS,
    ): Percent {
        $key = self::_stopLengthResultKey($symbol, $distanceSelector, $timeframe, $period);
        if (!isset($this->stopLengthResults[$key])) {
            throw new RuntimeException(sprintf('Cannot find mocked stopLengthResults result for %s', $key));
        }

        return $this->stopLengthResults[$key];
    }

    public function oppositeBuyLength(
        SymbolInterface $symbol,
        PriceDistanceSelector $distanceSelector,
        TimeFrame $timeframe = self::LONG_ATR_TIMEFRAME,
        int $period = self::ATR_PERIOD_FOR_ORDERS,
    ): Percent {
        $key = self::_oppositeBuyLengthResultsKey($symbol, $distanceSelector, $timeframe, $period);
        if (!isset($this->oppositeBuyLengthResults[$key])) {
            throw new RuntimeException(sprintf('Cannot find mocked oppositeBuyLengthResults result for %s', $key));
        }

        return $this->oppositeBuyLengthResults[$key];
    }

    public function standardAtrForOrdersLength(
        SymbolInterface $symbol,
        TimeFrame $timeframe = self::LONG_ATR_TIMEFRAME,
        int $period = self::ATR_PERIOD_FOR_ORDERS
    ): AveragePriceChange {
        throw new RuntimeException('Not implemented yet');
    }
}
