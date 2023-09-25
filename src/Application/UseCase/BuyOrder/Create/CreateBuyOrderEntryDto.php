<?php

declare(strict_types=1);

namespace App\Application\UseCase\BuyOrder\Create;

use App\Domain\Position\ValueObject\Side;

final readonly class CreateBuyOrderEntryDto
{
    private const TRIGGER_DELTA = 1;

    public function __construct(
        public Side $side,
        public float $volume,
        public float $price,
        public array $context = [],
        public float $triggerDelta = self::TRIGGER_DELTA
    ) {
    }
}
