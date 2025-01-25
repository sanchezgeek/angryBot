<?php

declare(strict_types=1);

namespace App\Application\UseCase\BuyOrder\Create;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;

final readonly class CreateBuyOrderEntryDto
{
    public function __construct(
        public Symbol $symbol,
        public Side $side,
        public float $volume,
        public float $price,
        public array $context = [],
    ) {
    }
}
