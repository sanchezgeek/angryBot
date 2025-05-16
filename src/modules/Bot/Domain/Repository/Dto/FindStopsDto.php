<?php

declare(strict_types=1);

namespace App\Bot\Domain\Repository\Dto;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Price;

final readonly class FindStopsDto
{
    public function __construct(
        public Symbol $symbol,
        public Side $positionSide,
        public Price $currentPrice,
    ) {
    }
}
