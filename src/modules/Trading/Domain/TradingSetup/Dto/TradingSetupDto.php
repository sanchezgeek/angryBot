<?php

declare(strict_types=1);

namespace App\Trading\Domain\TradingSetup\Dto;

use App\Domain\Value\Percent\Percent;
use App\Trading\Domain\Symbol\SymbolInterface;

final class TradingSetupDto
{
    public function __construct(
        SymbolInterface $symbol,
        Percent $depositRiskPercent
    ) {
    }
}
