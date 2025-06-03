<?php

declare(strict_types=1);

namespace App\Trading\Application\Parameters;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Position\ValueObject\Side;

interface TradingParametersProviderInterface
{
    public function safeLiquidationPriceDelta(SymbolInterface $symbol, Side $side, float $refPrice): float;
}
