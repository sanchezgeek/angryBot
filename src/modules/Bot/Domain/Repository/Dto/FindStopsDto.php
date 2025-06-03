<?php

declare(strict_types=1);

namespace App\Bot\Domain\Repository\Dto;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;

final readonly class FindStopsDto
{
    public function __construct(
        public SymbolInterface $symbol,
        public Side $positionSide,
        public SymbolPrice $currentPrice,
    ) {
    }
}
