<?php

declare(strict_types=1);

namespace App\Domain\Percent\ValueObject;

use App\Domain\Percent\AbstractFloatValue;
use App\Domain\Percent\IntegerValue;
use DomainException;

use InvalidArgumentException;

use function is_numeric;
use function sprintf;
use function str_ends_with;
use function substr;

final class Percent
{
    private float $value;

    public function __construct(float $value)
    {
        if ($value <= 0 || $value > 100) {
            throw new DomainException(sprintf('Percent value must be in 0..100 range. "%.2f" given.', $value));
        }

        $this->value = $value;
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

    public function sub(Percent $percent): self
    {
        return new self($this->value - $percent->value);
    }

    public function add(Percent $percent): self
    {
        return new self($this->value + $percent->value);
    }

    public function value(): float
    {
        return $this->value;
    }

    public function part(): float
    {
        return $this->value / 100;
    }

    public function of(int|float|AbstractFloatValue|IntegerValue $value): int|float|AbstractFloatValue|IntegerValue
    {
        if ($value instanceof AbstractFloatValue) {
            return $value->getPercentPart($this);
        }

        return $value * $this->part();
    }
}
