<?php

declare(strict_types=1);

namespace App\Trading\Application\Parameters;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;

interface TradingParametersProviderInterface
{
    public function safeLiquidationPriceDelta(Symbol $symbol, Side $side, float $refPrice): float;
}
