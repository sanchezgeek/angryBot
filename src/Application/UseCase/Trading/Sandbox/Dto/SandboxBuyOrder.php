<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Dto;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;

readonly class SandboxBuyOrder
{
    public function __construct(public Symbol $symbol, public Side $positionSide, public float $price, public float $volume)
    {
    }

    public static function fromBuyOrder(BuyOrder $buyOrder): self
    {
        return new self($buyOrder->getSymbol(), $buyOrder->getPositionSide(), $buyOrder->getPrice(), $buyOrder->getVolume());
    }
}