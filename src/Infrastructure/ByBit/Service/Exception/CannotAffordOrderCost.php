<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service\Exception;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;

final class CannotAffordOrderCost extends \Exception
{
    private function __construct(
        public readonly Symbol $symbol,
        public readonly Side $side,
        public readonly float $qty
    ) {
        parent::__construct(
            \sprintf(
                'CannotAffordOrderCost when try to buy $%.2f on %s %s position.',
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
