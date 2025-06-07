<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\PushOrdersToExchange;

use App\Bot\Domain\Position;
use App\Domain\Position\ValueObject\Side;
use App\Trading\Domain\Symbol\SymbolInterface;
use InvalidArgumentException;

/**
 * @codeCoverageIgnore
 */
final readonly class PushStops
{
    public function __construct(
        public SymbolInterface $symbol,
        public Side $side,
        public ?Position $positionState = null,
    ) {
        if ($this->positionState && $this->positionState->side !== $this->side) {
            throw new InvalidArgumentException(sprintf('Provided $position.side (%s) !== $side (%s)', $this->positionState->side->value, $this->side->value));
        }

        if ($this->positionState && !$this->positionState->symbol->eq($this->symbol)) {
            throw new InvalidArgumentException(sprintf('Provided $position.symbol (%s) !== $symbol (%s)', $this->positionState->symbol->name(), $this->symbol->name()));
        }
    }
}
