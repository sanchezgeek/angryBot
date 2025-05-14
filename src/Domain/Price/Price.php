<?php

declare(strict_types=1);

namespace App\Domain\Price;

use App\Bot\Domain\Position;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Enum\PriceMovementDirection;
use App\Domain\Price\Exception\PriceCannotBeLessThanZero;
use App\Domain\Price\Helper\PriceHelper;
use App\Domain\Stop\Helper\PnlHelper;
use RuntimeException;

use Stringable;

use function abs;
use function round;
use function sprintf;

/**
 * @see \App\Tests\Unit\Domain\Price\PriceTest
 *
 * @todo | ctrl-f: round(..., 2) | Need to pass precision in __construct on creation (in order to using not only in BTCUSDT context)
 */
final class Price implements Stringable
{
    private float $value;
    public ?int $precision = null;

    /**
     * @throws PriceCannotBeLessThanZero
     * @todo | Get precision on construct to further use. Maybe even external (with some getter)
     *         While accurate value must be calculated somewhere else and passed here
     */
    private function __construct(float $value, int $precision)
    {
        if ($value < 0) {
            throw new PriceCannotBeLessThanZero($value);
        }

        $this->value = $value;
        $this->precision = $precision;
    }

    /**
     * @todo | CS | rename to `fromFloat`?
     */
    public static function float(float $value, int $precision = null): self
    {
        $precision = $precision ?? 2;
        return new self($value, $precision);
    }

    /**
     * @todo | must be based on trading symbol
     */
    public function value(): float
    {
        return PriceHelper::round($this->value, $this->precision ?? 2);
    }

    public function add(Price|float $addValue): self
    {
        return self::float($this->value + self::toFloat($addValue), $this->precision);
    }

    public function sub(Price|float $subValue): self
    {
        return self::float($this->value - self::toFloat($subValue), $this->precision);
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
        return $this->isPriceInLossOfOther($positionSide, $stopPrice);
    }

    public function isPriceInLossOfOther(Side $positionSide, Price|float $other): bool
    {
        return $positionSide->isShort() ? $this->value > $other : $this->value < $other;
    }

    public function differenceWith(Price $otherPrice): PriceMovement
    {
        return PriceMovement::fromToTarget($otherPrice, $this);
    }

    public function deltaWith(Price|float $otherPrice): float
    {
        return PriceHelper::round(abs($this->value() - self::toFloat($otherPrice)), $this->precision);
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

    public static function toObj(self|float $value, int $precision = null): self
    {
        return $value instanceof self ? $value : self::float($value, $precision);
    }

    public function __toString(): string
    {
        return (string)$this->value();
    }
}
