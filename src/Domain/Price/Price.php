<?php

declare(strict_types=1);

namespace App\Domain\Price;

use App\Bot\Domain\Position;
use App\Domain\Price\Helper\PriceHelper;
use App\Domain\Stop\Helper\PnlHelper;

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

        $this->value = $value;
    }

    public static function float(float $value): self
    {
        return new self(PriceHelper::round($value));
    }

    public function value(): float
    {
        return $this->value;
    }

    public function add(float $value): self
    {
        return self::float($this->value + $value);
    }

    public function sub(float $value): self
    {
        return self::float($this->value - $value);
    }

    public function eq(Price $price): bool
    {
        return $this->value === $price->value;
    }

    public function greater(Price $price): bool
    {
        return $this->value > $price->value;
    }

    public function less(Price $price): bool
    {
        return $this->value < $price->value;
    }

    public function greaterOrEquals(Price $price): bool
    {
        return $this->value >= $price->value;
    }

    public function lessOrEquals(Price $price): bool
    {
        return $this->value <= $price->value;
    }

    public function pnlFor(Position $position): float
    {
        return PnlHelper::getPnlInPercents($position, $this->value());
    }
}
