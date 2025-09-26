<?php

declare(strict_types=1);

namespace App\Tests\Stub\TA;

use App\Domain\Position\ValueObject\Side;
use App\Domain\Trading\Enum\PriceDistanceSelector;
use App\Domain\Trading\Enum\RiskLevel;
use App\Domain\Trading\Enum\TimeFrame;
use App\Domain\Value\Percent\Percent;
use App\Liquidation\Domain\Assert\SafePriceAssertionStrategyEnum;
use App\TechnicalAnalysis\Domain\Dto\AveragePriceChange;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use RuntimeException;

class TradingParametersProviderStub implements TradingParametersProviderInterface
{
    public array $stopLengthResults = [];

    /** @var Percent[] */
    public array $oneDaySignificantPriceChangeResults = [];

    public static function riskLevel(SymbolInterface $symbol, Side $side): RiskLevel
    {
        return RiskLevel::Conservative;
    }

    private function _stopLengthResultKey(SymbolInterface $symbol, PriceDistanceSelector $distanceSelector, TimeFrame $timeframe, int $period): string
    {
        return sprintf('stopLengthResultKey_%s_%s_%s_%s', $symbol->name(), $distanceSelector->value, $timeframe->value, $period);
    }

    public function addTransformedLengthResult(
        Percent $percentResult,
        SymbolInterface $symbol,
        PriceDistanceSelector $distanceSelector,
        TimeFrame $timeframe = TradingParametersProviderInterface::ATR_TIMEFRAME_FOR_ORDERS,
        int $period = TradingParametersProviderInterface::ATR_PERIOD_FOR_ORDERS,
    ): self {
        $key = self::_stopLengthResultKey($symbol, $distanceSelector, $timeframe, $period);

        $this->stopLengthResults[$key] = $percentResult;

        return $this;
    }

    public function transformLengthToPricePercent(
        SymbolInterface $symbol,
        PriceDistanceSelector $length,
        TimeFrame $timeframe = self::LONG_ATR_TIMEFRAME,
        int $period = self::ATR_PERIOD_FOR_ORDERS,
    ): Percent {
        $key = self::_stopLengthResultKey($symbol, $length, $timeframe, $period);
        if (!isset($this->stopLengthResults[$key])) {
            throw new RuntimeException(sprintf('Cannot find mocked stopLengthResults result for %s', $key));
        }

        return $this->stopLengthResults[$key];
    }

    private function _significantPriceChangeResultKey(SymbolInterface $symbol): string
    {
        return sprintf('significantPriceChange_%s', $symbol->name());
    }

    public function addSignificantPriceChangeResult(
        SymbolInterface $symbol,
        Percent $oneDayPriceChangePercent,
    ): self {
        $key = self::_significantPriceChangeResultKey($symbol);

        $this->oneDaySignificantPriceChangeResults[$key] = $oneDayPriceChangePercent;

        return $this;
    }

    public function significantPriceChange(
        SymbolInterface $symbol,
        float $passedPartOfDay,
        ?float $atrBaseMultiplierOverride = null,
    ): Percent {
        $key = self::_significantPriceChangeResultKey($symbol);
        if (!$oneDaySignificantPriceChangeResults = $this->oneDaySignificantPriceChangeResults[$key] ?? null) {
            throw new RuntimeException(sprintf('Cannot find mocked oneDaySignificantPriceChangeResults result for %s', $key));
        }

        return new Percent($oneDaySignificantPriceChangeResults->value() * $passedPartOfDay);
    }

    public function safeLiquidationPriceDelta(
        SymbolInterface $symbol,
        Side $side,
        float $refPrice
    ): float {
        throw new RuntimeException('Not implemented yet');
    }

    public static function safePriceDistanceApplyStrategy(SymbolInterface $symbol, Side $positionSide): SafePriceAssertionStrategyEnum
    {
        throw new RuntimeException('Not implemented yet');
    }

    public function standardAtrForOrdersLength(
        SymbolInterface $symbol,
        TimeFrame $timeframe = self::LONG_ATR_TIMEFRAME,
        int $period = self::ATR_PERIOD_FOR_ORDERS
    ): AveragePriceChange {
        throw new RuntimeException('Not implemented yet');
    }
}
