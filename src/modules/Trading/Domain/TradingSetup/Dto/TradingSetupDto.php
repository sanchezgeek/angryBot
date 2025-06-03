<?php

declare(strict_types=1);

namespace App\Trading\Domain\TradingSetup\Dto;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Value\Percent\Percent;

final class TradingSetupDto
{
    public function __construct(
        SymbolInterface $symbol,
        Percent $depositRiskPercent
    ) {
    }
}
