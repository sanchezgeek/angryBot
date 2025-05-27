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
    public function __construct(
        float $value,
        bool $strict = true,
        private ?int $outputDecimalsPrecision = null,
        private ?int $outputFloatPrecision = null,
    ) {
        if ($strict && ($value <= 0 || $value > 100)) {
            throw new DomainException(sprintf('Percent value must be in 0..100 range. "%.2f" given.', $value));
        }

        parent::__construct($value);
    }

    public static function notStrict(float $percent): self
    {
        return new self($percent, false);
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function string(string $percent, bool $strict = true): self
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

        return new self($value, $strict);
    }

    public static function fromPart(float $part, bool $strict = true): self
    {
        return new self($part * 100, $strict);
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
        $floatPrecision = $this->outputFloatPrecision ?? 3;

        if ($this->outputDecimalsPrecision) {
            return sprintf('% ' . $this->outputDecimalsPrecision . '.' . $floatPrecision . 'f%%', $this->value());
        }

        return sprintf('%.' . $floatPrecision . 'f%%', $this->value());
    }

    public function jsonSerialize(): string
    {
        return (string)$this;
    }

    public function setOutputDecimalsPrecision(int $outputDecimalsPrecision): self
    {
        $this->outputDecimalsPrecision = $outputDecimalsPrecision;

        return $this;
    }

    public function setOutputFloatPrecision(int $outputFloatPrecision): self
    {
        $this->outputFloatPrecision = $outputFloatPrecision;

        return $this;
    }
}
