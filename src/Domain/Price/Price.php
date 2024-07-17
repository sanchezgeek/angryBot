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
 *
 * @todo | ctrl-f: round(..., 2) | Need to pass precision in __construct on creation (in order to using not only in BTCUSDT context)
 */
readonly final class Price
{
    private const MIN = 0.01;

    private float $value;

    private function __construct(float $value)
    {
        if ($value <= 0) {
            throw new \DomainException('Price cannot be less or equals zero.');
        }

        if ($value < self::MIN) {
            throw new \DomainException('Price cannot be less min available value.');
        }

        $this->value = $value;
    }

    public static function float(float $value): self
    {
        return new self($value);
    }

    /**
     * @todo | move to service (must be based on traded symbol)
     */
    public static function min(): self
    {
        return new self(self::MIN);
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
        return round(abs($this->value() - self::toFloat($otherPrice)), 2);
    }

    public function modifyByDirection(Side $positionSide, PriceMovementDirection $direction, Price|float $diff): self
    {
        return match ($direction) {
            PriceMovementDirection::TO_LOSS => $positionSide->isShort() ? $this->add($diff) : $this->sub($diff),
            PriceMovementDirection::TO_PROFIT => $positionSide->isShort() ? $this->sub($diff) : $this->add($diff),
            default => throw new RuntimeException(sprintf('Unknown direction "%s".', $direction->name))
        };
    }

    public static function toFloat(self|float $value): float
    {
        return $value instanceof self ? $value->value : $value;
    }

    public static function toObj(self|float $value): self
    {
        return $value instanceof self ? $value : self::float($value);
    }
}
