<?php

declare(strict_types=1);

namespace App\Domain\Value\Percent;

use App\Domain\Value\Common\AbstractFloat;
use App\Domain\Value\Common\IntegerValue;
use DomainException;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

use function is_numeric;
use function round;
use function sprintf;
use function str_ends_with;
use function substr;

/**
 * @see \App\Tests\Unit\Domain\Value\Percent\PercentTest
 */
final class Percent extends AbstractFloat implements Stringable, JsonSerializable
{
    public function __construct(float $value, bool $strict = true)
    {
        if ($strict && ($value <= 0 || $value > 100)) {
            throw new DomainException(sprintf('Percent value must be in 0..100 range. "%.2f" given.', $value));
        }

        parent::__construct($value);
    }

    public static function string(string $percent): self
    {
        if (
            !str_ends_with($percent, '%')
            || !is_numeric($value = substr($percent, 0, -1))
        ) {
            throw new InvalidArgumentException(
                sprintf('Invalid percent string provided ("%s").', $percent)
            );
        }

        $value = (float)$value;

        return new self($value);
    }

    public function part(): float
    {
        return round($this->value() / 100, 7);
    }

    public function of(int|float|IntegerValue|AbstractFloat $value): float|AbstractFloat
    {
        if ($value instanceof AbstractFloat) {
            return $value->getPercentPart($this);
        }

        if ($value instanceof IntegerValue) {
            $value = $value->value();
        }

        return $value * $this->part();
    }

    public function __toString(): string
    {
        return (string)$this->value();
    }

    public function jsonSerialize(): string
    {
        return (string)$this;
    }
}
