<?php

declare(strict_types=1);

namespace App\Domain\Price;

use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Enum\PriceMovementDirection;
use App\Domain\Price\Helper\PriceHelper;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Value\Percent\Percent;

use App\Helper\FloatHelper;

use function abs;

/**
 * @see \App\Tests\Unit\Domain\Price\PriceMovementTest
 */
readonly class PriceMovement
{
    private Price $fromPrice;
    private Price $toTargetPrice;

    private function __construct(float|Price $fromPrice, float|Price $toTargetPrice)
    {
        $this->fromPrice = Price::toObj($fromPrice);
        $this->toTargetPrice = Price::toObj($toTargetPrice);
    }

    public static function fromToTarget(float|Price $fromPrice, float|Price $toTargetPrice): self
    {
        return new self($fromPrice, $toTargetPrice);
    }

    public function absDelta(): float
    {
        return PriceHelper::round(abs($this->fromPrice->value() - $this->toTargetPrice->value()));
    }

    public function deltaForPositionProfit(Side $positionSide): float
    {
        $result = $positionSide->isShort() ? $this->fromPrice->value() - $this->toTargetPrice->value() : $this->toTargetPrice->value() - $this->fromPrice->value();

        // @todo | make round with initial prices precision (get from Price object)
        return FloatHelper::round($result);
    }

    public function deltaForPositionLoss(Side $positionSide): float
    {
        return -$this->deltaForPositionProfit($positionSide);
    }

    public function percentDeltaForPositionProfit(Side $relativeToPositionSide, float|Price $price = null): Percent
    {
        $price ??= $this->fromPrice;

        $delta = $this->deltaForPositionProfit($relativeToPositionSide);
        return PnlHelper::convertAbsDeltaToPnlPercentOnPrice($delta, $price);
    }

    /**
     * If, e.g., need to get "+" in case of movement to position losses. Good example in StopInfoCommand (in sense increasing of liquidation)
     */
    public function percentDeltaForPositionLoss(Side $relativeToPositionSide, float|Price $price = null): Percent
    {
        $price ??= $this->fromPrice;

        $delta = $this->deltaForPositionLoss($relativeToPositionSide);
        return PnlHelper::convertAbsDeltaToPnlPercentOnPrice($delta, $price);
    }

    public function isLossFor(Side $positionSide): bool
    {
        return (bool)$this->movementDirection($positionSide)?->isLoss();
    }

    public function isProfitFor(Side $positionSide): bool
    {
        return (bool)$this->movementDirection($positionSide)?->isProfit();
    }

    private function movementDirection(Side $relatedToPositionSide): ?PriceMovementDirection
    {
        if ($this->toTargetPrice->greaterThan($this->fromPrice)) {
            return $relatedToPositionSide->isShort() ? PriceMovementDirection::TO_LOSS : PriceMovementDirection::TO_PROFIT;
        }

        if ($this->fromPrice->greaterThan($this->toTargetPrice)) {
            return $relatedToPositionSide->isShort() ? PriceMovementDirection::TO_PROFIT : PriceMovementDirection::TO_LOSS;
        }

        return null;
    }

    public function pnlToString(Side $relativeToPositionSide): string
    {
        $absDelta = $this->absDelta();
        if ($absDelta === 0.0) {
            return '0';
        }

        return $this->isProfitFor($relativeToPositionSide) ? '+' . $absDelta : '-' . $absDelta;
    }
}
