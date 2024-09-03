<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\MarketBuy\Dto;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;

readonly class MarketBuyEntryDto
{
    public function __construct(
        public Symbol $symbol,
        public Side $positionSide,
        public float $volume,
        public bool $force = false
    ) {
    }

    public static function fromBuyOrder(BuyOrder $buyOrder): self
    {
        $force = false;
        if ($buyOrder->isOppositeBuyOrderAfterStopLoss()) {
            $force = true;
        }

        if ($buyOrder->isForceBuyOrder()) {
            $force = true;
        }

        return new self($buyOrder->getSymbol(), $buyOrder->getPositionSide(), $buyOrder->getVolume(), $force);
    }
}