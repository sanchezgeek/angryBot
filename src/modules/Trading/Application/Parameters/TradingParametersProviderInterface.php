<?php

declare(strict_types=1);

namespace App\Trading\Application\Parameters;

use App\Domain\Position\ValueObject\Side;
use App\Trading\Domain\Symbol\SymbolInterface;

interface TradingParametersProviderInterface
{
    public function safeLiquidationPriceDelta(SymbolInterface $symbol, Side $side, float $refPrice): float;
}
