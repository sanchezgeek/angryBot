<?php

declare(strict_types=1);

namespace App\Trading\Application\Parameters;

use App\Domain\Position\ValueObject\Side;
use App\Domain\Trading\Enum\PredefinedStopLengthSelector;
use App\Domain\Trading\Enum\TimeFrame;
use App\Domain\Value\Percent\Percent;
use App\TechnicalAnalysis\Domain\Dto\AveragePriceChange;
use App\Trading\Domain\Symbol\SymbolInterface;

interface TradingParametersProviderInterface
{
    public const TimeFrame LONG_ATR_TIMEFRAME = TimeFrame::D1;
    public const int LONG_ATR_PERIOD = 10;
    public const int ATR_PERIOD_FOR_ORDERS = 4;

    public function safeLiquidationPriceDelta(SymbolInterface $symbol, Side $side, float $refPrice): float;
    public function significantPriceChange(SymbolInterface $symbol, float $passedPartOfDay): Percent;
    public function standardAtrForOrdersLength(SymbolInterface $symbol, TimeFrame $timeframe = self::LONG_ATR_TIMEFRAME, int $period = self::ATR_PERIOD_FOR_ORDERS): AveragePriceChange;
    public function regularPredefinedStopLength(SymbolInterface $symbol, PredefinedStopLengthSelector $predefinedStopLength, TimeFrame $timeframe, int $period): Percent;
    public function regularOppositeBuyOrderLength(SymbolInterface $symbol, PredefinedStopLengthSelector $sourceStopLength, TimeFrame $timeframe, int $period): Percent;
}
