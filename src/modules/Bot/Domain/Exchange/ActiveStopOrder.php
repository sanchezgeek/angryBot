<?php

declare(strict_types=1);

namespace App\Bot\Domain\Exchange;

use App\Domain\Position\ValueObject\Side;
use App\Trading\Domain\Symbol\Entity\Symbol;
use App\Trading\Domain\Symbol\SymbolContainerInterface;
use App\Trading\Domain\Symbol\SymbolInterface;

final class ActiveStopOrder implements SymbolContainerInterface
{
    public function __construct(
        public SymbolInterface $symbol,
        public readonly Side $positionSide,
        public readonly string $orderId,
        public readonly float $volume,
        public readonly float $triggerPrice,
        public readonly string $triggerBy,
    ) {
    }

    public function getSymbol(): SymbolInterface
    {
        return $this->symbol;
    }

    /**
     * @internal For tests
     */
    public function replaceSymbolEntity(Symbol $symbol): self
    {
        $this->symbol = $symbol;

        return $this;
    }
}
