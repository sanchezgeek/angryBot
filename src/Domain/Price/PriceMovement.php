<?php

declare(strict_types=1);

namespace App\Domain\Price;

use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Enum\PriceMovementDirection;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;

use function abs;

/**
 * @see \App\Tests\Unit\Domain\Price\PriceMovementTest
 */
readonly class PriceMovement
{
    private function __construct(private SymbolPrice $fromPrice, private SymbolPrice $toTargetPrice)
    {
    }

    public static function fromToTarget(SymbolPrice $fromPrice, SymbolPrice $toTargetPrice): self
    {
        return new self($fromPrice, $toTargetPrice);
    }

    public function absDelta(): float
    {
        return FloatHelper::round(abs($this->fromPrice->value() - $this->toTargetPrice->value()), $this->fromPrice->symbol->pricePrecision());
    }

    public function deltaForPositionProfit(Side $positionSide): float
    {
        $delta = $positionSide->isShort()
            ? SymbolPrice::toFloat($this->fromPrice) - SymbolPrice::toFloat($this->toTargetPrice)
            : SymbolPrice::toFloat($this->toTargetPrice) - SymbolPrice::toFloat($this->fromPrice)
        ;

        return FloatHelper::round($delta, $this->fromPrice->symbol->pricePrecision());
    }

    public function deltaForPositionLoss(Side $positionSide): float
    {
        return -$this->deltaForPositionProfit($positionSide);
    }

    public function absPercentDelta(float|SymbolPrice|null $forPrice = null): Percent
    {
        $forPrice ??= $this->fromPrice;
        $delta = $this->absDelta();

        return PnlHelper::convertAbsDeltaToPnlPercentOnPrice($delta, $forPrice);
    }

    public function percentDeltaForPositionProfit(Side $relativeToPositionSide, float|SymbolPrice|null $price = null): Percent
    {
        $price ??= $this->fromPrice;

        $delta = $this->deltaForPositionProfit($relativeToPositionSide);
        return PnlHelper::convertAbsDeltaToPnlPercentOnPrice($delta, $price);
    }

    /**
     * If, e.g., need to get "+" in case of movement to position losses. Good example in StopInfoCommand (in sense increasing of liquidation)
     */
    public function percentDeltaForPositionLoss(Side $relativeToPositionSide, float|SymbolPrice|null $price = null): Percent
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

    public function movementDirection(Side $relatedToPositionSide): ?PriceMovementDirection
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
