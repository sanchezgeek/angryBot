<?php

declare(strict_types=1);

namespace App\Application\UseCase\BuyOrder\Create;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;

final class CreateBuyOrderEntryDto
{
    public function __construct(
        public readonly Symbol $symbol,
        public readonly Side $side,
        public readonly float $volume,
        public readonly float $price,
        public array $context = [],
    ) {
    }
}
