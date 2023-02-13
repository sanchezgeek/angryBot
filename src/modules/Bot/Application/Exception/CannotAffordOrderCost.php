<?php

declare(strict_types=1);

namespace App\Bot\Application\Exception;

use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Domain\ValueObject\Symbol;

final class CannotAffordOrderCost extends \Exception
{
    private function __construct(
        public readonly Symbol $symbol,
        public readonly Side $side,
        public readonly float $qty
    ) {
        parent::__construct('CannotAffordOrderCost');
    }

    public static function forBuy(Symbol $symbol, Side $side, float $qty): self
    {
        return new self($symbol, $side, $qty);
    }
}
