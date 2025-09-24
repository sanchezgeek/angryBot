<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Domain\Dto\HighLow;

use App\Domain\Price\SymbolPrice;
use Stringable;

final readonly class HighLowPrices implements Stringable
{
    public function __construct(
        public SymbolPrice $high,
        public SymbolPrice $low,
    ) {
    }

    public function delta(): float
    {
        return $this->high->value() - $this->low->value();
    }

    public function __toString(): string
    {
        return sprintf('high = %s, low = %s', $this->high, $this->low);
    }
}
