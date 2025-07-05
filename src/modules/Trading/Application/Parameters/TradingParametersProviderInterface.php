<?php

declare(strict_types=1);

namespace App\Trading\Application\Parameters;

use App\Domain\Position\ValueObject\Side;
use App\Domain\Trading\Enum\PredefinedStopLengthSelector;
use App\Domain\Trading\Enum\TimeFrame;
use App\Domain\Value\Percent\Percent;
use App\Trading\Domain\Symbol\SymbolInterface;

interface TradingParametersProviderInterface
{
    public function safeLiquidationPriceDelta(SymbolInterface $symbol, Side $side, float $refPrice): float;
    public function significantPriceChange(SymbolInterface $symbol, float $passedPartOfDay): Percent;
    public function regularPredefinedStopLength(SymbolInterface $symbol, PredefinedStopLengthSelector $predefinedStopLength, TimeFrame $timeframe, int $period): Percent;
    public function regularOppositeBuyOrderLength(SymbolInterface $symbol, PredefinedStopLengthSelector $sourceStopLength, TimeFrame $timeframe, int $period): Percent;
}
