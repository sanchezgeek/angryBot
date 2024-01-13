<?php

declare(strict_types=1);

namespace App\Domain\Price;

use App\Bot\Domain\Position;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Helper\PriceHelper;
use App\Domain\Stop\Helper\PnlHelper;

use function round;

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
        $addValue = $addValue instanceof self ? $addValue->value : $addValue;

        return self::float($this->value + $addValue);
    }

    public function sub(Price|float $subValue): self
    {
        $subValue = $subValue instanceof self ? $subValue->value : $subValue;

        return self::float($this->value - $subValue);
    }

    public function eq(Price $price): bool
    {
        return $this->value === $price->value;
    }

    public function greaterThan(Price $price): bool
    {
        return $this->value > $price->value;
    }

    public function greaterOrEquals(Price $price): bool
    {
        return $this->value >= $price->value;
    }

    public function lessThan(Price $price): bool
    {
        return $this->value < $price->value;
    }

    public function lessOrEquals(Price $price): bool
    {
        return $this->value <= $price->value;
    }

    public function isPriceInRange(PriceRange $priceRange): bool
    {
        return $this->greaterOrEquals($priceRange->from()) && $this->lessOrEquals($priceRange->to());
    }

    public function getPnlPercentFor(Position $position): float
    {
        return PnlHelper::getPnlInPercents($position, $this->value());
    }

    public function isPriceOverTakeProfit(Side $positionSide, float $takeProfitPrice): bool
    {
        return $positionSide->isShort() ? $this->value <= $takeProfitPrice : $this->value >= $takeProfitPrice;
    }

    public function isPriceOverStop(Side $positionSide, float $stopPrice): bool
    {
        return $positionSide->isShort() ? $this->value >= $stopPrice : $this->value <= $stopPrice;
    }

    public function differenceWith(Price $price): PriceMovement
    {
        return PriceMovement::fromToTarget($price, $this);
    }
}
