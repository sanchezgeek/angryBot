<?php

declare(strict_types=1);

namespace App\Application\Messenger\Trading\CoverLossesAfterCloseByMarket;

use App\Bot\Domain\Position;
use App\Domain\Coin\CoinAmount;

readonly class CoverLossesAfterCloseByMarketConsumerDto
{
    private function __construct(
        public Position $closedPosition,
        public CoinAmount $loss
    ) {
    }

    public static function forPosition(Position $position, float $loss): self
    {
        return new self($position, new CoinAmount($position->symbol->associatedCoin(), $loss));
    }
}