<?php

declare(strict_types=1);

namespace App\Stop\Application\Contract\Command;

use App\Domain\Position\ValueObject\Side;
use App\Trading\Domain\Symbol\SymbolInterface;

final readonly class CreateStop
{
    public function __construct(
        public SymbolInterface $symbol,
        public Side $positionSide,
        public float $volume,
        public float $price,
        public ?float $triggerDelta = null,
        public array $context = [],
        public ?int $id = null,
    ) {
    }

    public function addContext(array $context): self
    {
        return new self(
            symbol: $this->symbol,
            positionSide: $this->positionSide,
            volume: $this->volume,
            price: $this->price,
            triggerDelta: $this->triggerDelta,
            context: array_merge($this->context, $context),
            id: $this->id
        );
    }
}
