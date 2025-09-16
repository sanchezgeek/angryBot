<?php

declare(strict_types=1);

namespace App\Domain\Price;

use App\Bot\Domain\Position;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Enum\PriceMovementDirection;
use App\Domain\Price\Exception\PriceCannotBeLessThanZero;
use App\Domain\Price\Helper\PriceHelper;
use App\Domain\Stop\Helper\PnlHelper;
use App\Trading\Domain\Symbol\SymbolInterface;
use JsonSerializable;
use RuntimeException;
use Stringable;

use function abs;
use function sprintf;

/**
 * @see \App\Tests\Unit\Domain\Price\PriceTest
 *
 * @todo | ctrl-f: round(..., 2)
 */
final readonly class SymbolPrice implements Stringable, JsonSerializable
{
    /**
     * @throws PriceCannotBeLessThanZero
     */
    private function __construct(private float $value, public SymbolInterface $symbol)
    {
        if ($this->value < 0) {
            throw new PriceCannotBeLessThanZero($value, $this->symbol);
        }
    }

    /**
     * @throws PriceCannotBeLessThanZero
     */
    public static function create(float $value, SymbolInterface $source): self
    {
        return new self($value, $source);
    }

    /**
     * @todo | must be based on trading symbol
     */
    public function value(): float
    {
        return PriceHelper::round($this->value, $this->symbol->pricePrecision() ?? 2);
    }

    /**
     * @throws PriceCannotBeLessThanZero
     */
    public function add(SymbolPrice|float $addValue): self
    {
        return self::create($this->value + self::toFloat($addValue), $this->symbol);
    }

    /**
     * @throws PriceCannotBeLessThanZero
     */
    public function sub(SymbolPrice|float $subValue, bool $zeroSafe = false): self
    {
        $value = $this->value - self::toFloat($subValue);

        if ($zeroSafe && $value < 0) {
            $value = 0;
        }

        return self::create($value, $this->symbol);
    }

    public function eq(SymbolPrice|float $otherPrice): bool
    {
        return $this->value === self::toFloat($otherPrice);
    }

    public function greaterThan(SymbolPrice|float $otherPrice): bool
    {
        return $this->value > self::toFloat($otherPrice);
    }

    public function greaterOrEquals(SymbolPrice|float $otherPrice): bool
    {
        return $this->value >= self::toFloat($otherPrice);
    }

    public function lessThan(SymbolPrice|float $otherPrice): bool
    {
        return $this->value < self::toFloat($otherPrice);
    }

    public function lessOrEquals(SymbolPrice|float $otherPrice): bool
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
        return PnlHelper::targetPriceByPnlPercent($this, $pnlPercent, $position->side);
    }

    public function isPriceOverTakeProfit(Side $positionSide, float $takeProfitPrice): bool
    {
        return $positionSide->isShort() ? $this->value <= $takeProfitPrice : $this->value >= $takeProfitPrice;
    }

    public function isPriceOverStop(Side $positionSide, float $stopPrice): bool
    {
        return $this->isPriceInLossOfOther($positionSide, $stopPrice);
    }

    public function isPriceInLossOfOther(Side $positionSide, SymbolPrice|float $other): bool
    {
        return $positionSide->isShort() ? $this->value > $other : $this->value < $other;
    }

    public function differenceWith(SymbolPrice $otherPrice): PriceMovement
    {
        return PriceMovement::fromToTarget($otherPrice, $this);
    }

    public function deltaWith(SymbolPrice|float $otherPrice, bool $round = true): float
    {
        $delta = abs($this->value() - self::toFloat($otherPrice));

        return $round ? PriceHelper::round($delta, $this->symbol->pricePrecision()) : $delta;
    }

    public function modifyByDirection(Side $positionSide, PriceMovementDirection $direction, SymbolPrice|float $diff): self
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

    public function __toString(): string
    {
        return (string)$this->value();
    }

    public function jsonSerialize(): array
    {
        return [
            'value' => $this->value,
            'symbol' => $this->symbol,
        ];
    }
}
