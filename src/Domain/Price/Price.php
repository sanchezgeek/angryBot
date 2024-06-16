<?php

declare(strict_types=1);

namespace App\Domain\Price;

use App\Bot\Domain\Position;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Enum\PriceMovementDirection;
use App\Domain\Stop\Helper\PnlHelper;

use RuntimeException;

use function abs;
use function round;
use function sprintf;

/**
 * @see \App\Tests\Unit\Domain\Price\PriceTest
 */
readonly final class Price
{
    private float $value;

    private function __construct(float $value)
    {
        if ($value <= 0) {
            throw new \DomainException('Price cannot be less or equals zero.');
        }

        if ($value < 0.01) {
            throw new \DomainException('Price cannot be less min available value.');
        }

        $this->value = $value;
    }

    public static function float(float $value): self
    {
        return new self($value);
    }

    public function value(): float
    {
        return round($this->value, 2);
    }

    public function add(Price|float $addValue): self
    {
        return self::float($this->value + self::toFloat($addValue));
    }

    public function sub(Price|float $subValue): self
    {
        return self::float($this->value - self::toFloat($subValue));
    }

    public function eq(Price|float $otherPrice): bool
    {
        return $this->value === self::toFloat($otherPrice);
    }

    public function greaterThan(Price|float $otherPrice): bool
    {
        return $this->value > self::toFloat($otherPrice);
    }

    public function greaterOrEquals(Price|float $otherPrice): bool
    {
        return $this->value >= self::toFloat($otherPrice);
    }

    public function lessThan(Price|float $otherPrice): bool
    {
        return $this->value < self::toFloat($otherPrice);
    }

    public function lessOrEquals(Price|float $otherPrice): bool
    {
        return $this->value <= self::toFloat($otherPrice);
    }

    public function isPriceInRange(PriceRange $priceRange): bool
    {
        return $this->greaterOrEquals($priceRange->from()) && $this->lessOrEquals($priceRange->to());
    }

    public function getPnlPercentFor(Position $position): float
    {
        return PnlHelper::getPnlInPercents($position, $this->value());
    }

    public function getTargetPriceByPnlPercent(float $pnlPercent, Position $position): self
    {
        return PnlHelper::targetPriceByPnlPercent($this, $pnlPercent, $position);
    }

    public function isPriceOverTakeProfit(Side $positionSide, float $takeProfitPrice): bool
    {
        return $positionSide->isShort() ? $this->value <= $takeProfitPrice : $this->value >= $takeProfitPrice;
    }

    public function isPriceOverStop(Side $positionSide, float $stopPrice): bool
    {
        return $positionSide->isShort() ? $this->value >= $stopPrice : $this->value <= $stopPrice;
    }

    public function differenceWith(Price|float $otherPrice): PriceMovement
    {
        return PriceMovement::fromToTarget(self::toObj($otherPrice), $this);
    }

    public function deltaWith(Price|float $otherPrice): float
    {
        return abs($this->value - self::toFloat($otherPrice));
    }

    public function modifyByDirection(Side $positionSide, PriceMovementDirection $direction, Price|float $diff): self
    {
        return match ($direction) {
            PriceMovementDirection::TO_LOSS => $positionSide->isShort() ? $this->add($diff) : $this->sub($diff),
            PriceMovementDirection::TO_PROFIT => $positionSide->isShort() ? $this->sub($diff) : $this->add($diff),
            default => throw new RuntimeException(sprintf('Unknown direction "%s".', $direction->name))
        };
    }

    /**
     * @todo Or move to PriceHelper?
     */
    private static function toFloat(self|float $value): float
    {
        return $value instanceof self ? $value->value : $value;
    }

    private static function toObj(self|float $value): self
    {
        return $value instanceof self ? $value : self::float($value);
    }
}
