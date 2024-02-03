<?php

declare(strict_types=1);

namespace App\Domain\Price;

use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Enum\PriceMovementDirection;

use function abs;

readonly class PriceMovement
{
    private function __construct(private Price $fromPrice, private Price $toTargetPrice)
    {
    }

    public static function fromToTarget(Price $fromPrice, Price $toTargetPrice): self
    {
        return new self($fromPrice, $toTargetPrice);
    }

    public function delta(): float
    {
        return abs($this->fromPrice->value() - $this->toTargetPrice->value());
    }

    public function isLossFor(Side $positionSide): bool
    {
        return $this->movementDirection($positionSide)->isLoss();
    }

    public function isProfitFor(Side $positionSide): bool
    {
        return $this->movementDirection($positionSide)->isProfit();
    }

    public function movementDirection(Side $relatedToPositionSide): PriceMovementDirection
    {
        if ($this->toTargetPrice->greaterThan($this->fromPrice)) {
            return $relatedToPositionSide->isShort() ? PriceMovementDirection::TO_LOSS : PriceMovementDirection::TO_PROFIT;
        }

        if ($this->fromPrice->greaterThan($this->toTargetPrice)) {
            return $relatedToPositionSide->isShort() ? PriceMovementDirection::TO_PROFIT : PriceMovementDirection::TO_LOSS;
        }

        return PriceMovementDirection::NONE;
    }
}
