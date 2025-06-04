<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\Utils;

use App\Bot\Domain\Position;
use App\Domain\Position\ValueObject\Side;
use App\Trading\Domain\Symbol\SymbolInterface;

final readonly class MoveStops
{
    public function __construct(
        public SymbolInterface $symbol,
        public Side $positionSide
    ) {
    }

    public static function ofPosition(Position $position): self
    {
        return new self($position->symbol, $position->side);
    }
}
