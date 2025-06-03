<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\MarketBuy\Dto;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Position\ValueObject\Side;

readonly class MarketBuyEntryDto
{
    public function __construct(
        public SymbolInterface $symbol,
        public Side $positionSide,
        public float $volume,
        public bool $force = false,
        public ?BuyOrder $sourceBuyOrder = null,
    ) {
    }

    public static function fromBuyOrder(BuyOrder $buyOrder): self
    {
        return new self($buyOrder->getSymbol(), $buyOrder->getPositionSide(), $buyOrder->getVolume(), $buyOrder->isForceBuyOrder(), $buyOrder);
    }
}
