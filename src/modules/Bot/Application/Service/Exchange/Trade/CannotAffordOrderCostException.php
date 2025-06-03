<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Exchange\Trade;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Position\ValueObject\Side;
use Exception;

final class CannotAffordOrderCostException extends Exception
{
    private function __construct(
        public readonly SymbolInterface $symbol,
        public readonly Side $side,
        public readonly float $qty
    ) {
        parent::__construct(
            \sprintf(
                'CannotAffordOrderCost [buy %.3f, %s %s].',
                $this->qty,
                $this->symbol->value,
                $this->side->title()
            )
        );
    }

    public static function forBuy(SymbolInterface $symbol, Side $side, float $qty): self
    {
        return new self($symbol, $side, $qty);
    }
}
