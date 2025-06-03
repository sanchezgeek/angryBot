<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\PushOrdersToExchange;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Position\ValueObject\Side;
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

        if ($this->positionState && $this->positionState->symbol !== $this->symbol) {
            throw new InvalidArgumentException(sprintf('Provided $position.symbol (%s) !== $symbol (%s)', $this->positionState->symbol->value, $this->symbol->value));
        }
    }
}
