<?php

declare(strict_types=1);

namespace App\Bot\Domain;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;

final class Position
{
    public function __construct(
        public readonly Side $side,
        public readonly Symbol $symbol,
        public readonly float $entryPrice,
        public readonly float $size,
        public readonly float $positionValue,
        public readonly float $liquidationPrice,
        public readonly float $positionMargin,
        public readonly float $positionLeverage,
    ) {
    }

    public function getCaption(): string
    {
        $type = $this->side === Side::Sell ? 'SHORT' : 'LONG';

        return $this->symbol->value . ' ' . $type;
    }

    /**
     * @return float Delta between position->entryPrice and ticker->indexPrice (+ in case of profit / - case of losses)
     */
    public function getDeltaWithTicker(Ticker $ticker): float
    {
        return $this->side === Side::Sell
            ? $this->entryPrice - $ticker->indexPrice
            : $ticker->indexPrice - $this->entryPrice
        ;
    }
}
