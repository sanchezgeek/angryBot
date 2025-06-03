<?php

declare(strict_types=1);

namespace App\Application\UseCase\BuyOrder\Create;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Position\ValueObject\Side;

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
