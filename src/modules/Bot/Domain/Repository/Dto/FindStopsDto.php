<?php

declare(strict_types=1);

namespace App\Bot\Domain\Repository\Dto;

use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;
use App\Trading\Domain\Symbol\SymbolInterface;

final readonly class FindStopsDto
{
    public function __construct(
        public SymbolInterface $symbol,
        public Side $positionSide,
        public SymbolPrice $currentPrice,
    ) {
    }
}
