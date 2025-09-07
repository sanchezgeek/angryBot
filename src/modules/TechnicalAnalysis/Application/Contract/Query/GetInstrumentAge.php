<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Contract\Query;

use App\Trading\Domain\Symbol\SymbolInterface;

final class GetInstrumentAge
{
    public function __construct(
        public SymbolInterface $symbol,
    ) {
    }
}
