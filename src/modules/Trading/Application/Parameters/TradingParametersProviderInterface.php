<?php

declare(strict_types=1);

namespace App\Trading\Application\Parameters;

use App\Buy\Domain\Enum\PredefinedStopLengthSelector;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Trading\Enum\TimeFrame;
use App\Domain\Value\Percent\Percent;
use App\Trading\Domain\Symbol\SymbolInterface;

interface TradingParametersProviderInterface
{
    public function safeLiquidationPriceDelta(SymbolInterface $symbol, Side $side, float $refPrice): float;
    public function significantPriceChangePercent(SymbolInterface $symbol, float $passedPartOfDay): Percent;
    public function regularPredefinedStopLengthPercent(SymbolInterface $symbol, PredefinedStopLengthSelector $predefinedStopLength, TimeFrame $timeframe, int $period): Percent;
}
