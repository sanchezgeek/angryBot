<?php

declare(strict_types=1);

namespace App\Application\UseCase\BuyOrder\Create;

use App\Domain\Position\ValueObject\Side;
use App\Trading\Domain\Symbol\SymbolInterface;

final class CreateBuyOrderEntryDto
{
    public function __construct(
        public readonly SymbolInterface $symbol,
        public readonly Side $side,
        public readonly float $volume,
        public readonly float $price,
        public array $context = [],
    ) {
    }
}
