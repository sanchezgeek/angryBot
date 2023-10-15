<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service\Exception\Trade;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use Exception;

final class CannotAffordOrderCost extends Exception
{
    private function __construct(
        public readonly Symbol $symbol,
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

    public static function forBuy(Symbol $symbol, Side $side, float $qty): self
    {
        return new self($symbol, $side, $qty);
    }
}
