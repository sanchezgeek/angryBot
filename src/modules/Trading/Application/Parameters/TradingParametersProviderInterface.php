<?php

declare(strict_types=1);

namespace App\Trading\Application\Parameters;

use App\Domain\Position\ValueObject\Side;
use App\Domain\Trading\Enum\PriceDistanceSelector;
use App\Domain\Trading\Enum\TimeFrame;
use App\Domain\Trading\Enum\TradingStyle;
use App\Domain\Value\Percent\Percent;
use App\TechnicalAnalysis\Domain\Dto\AveragePriceChange;
use App\Trading\Domain\Symbol\SymbolInterface;

interface TradingParametersProviderInterface
{
    public const TimeFrame LONG_ATR_TIMEFRAME = TimeFrame::D1;
    public const int LONG_ATR_PERIOD = 10;
    public const int ATR_PERIOD_FOR_ORDERS = 4;

    public function tradingStyle(SymbolInterface $symbol, Side $side): TradingStyle;
    public function safeLiquidationPriceDelta(SymbolInterface $symbol, Side $side, float $refPrice): float;
    public function significantPriceChange(SymbolInterface $symbol, float $passedPartOfDay): Percent;
    public function standardAtrForOrdersLength(SymbolInterface $symbol, TimeFrame $timeframe = self::LONG_ATR_TIMEFRAME, int $period = self::ATR_PERIOD_FOR_ORDERS): AveragePriceChange;
    public function stopLength(SymbolInterface $symbol, PriceDistanceSelector $distanceSelector, TimeFrame $timeframe = self::LONG_ATR_TIMEFRAME, int $period = self::ATR_PERIOD_FOR_ORDERS): Percent;
    public function oppositeBuyLength(SymbolInterface $symbol, PriceDistanceSelector $distanceSelector, TimeFrame $timeframe = self::LONG_ATR_TIMEFRAME, int $period = self::ATR_PERIOD_FOR_ORDERS): Percent;
}
