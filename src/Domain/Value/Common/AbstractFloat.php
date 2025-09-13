<?php

declare(strict_types=1);

namespace App\Domain\Value\Common;

use App\Domain\Value\Percent\Percent;
use App\Domain\Value\Percent\PercentModifiableValue;
use LogicException;

use function get_class;
use function is_object;
use function sprintf;

/**
 * @see \App\Tests\Unit\Domain\Value\Common\AbstractFloatTest
 */
abstract class AbstractFloat implements PercentModifiableValue
{
    private float $value;

    public function __construct(float $value)
    {
        $this->value = $value;
    }

    public function value(): float
    {
        return $this->value;
    }

    public function abs(): static
    {
        $clone = clone $this;
        $clone->value = abs($this->value);

        return $clone;
    }

    final public function add(float|AbstractFloat $otherFloat): static
    {
        is_object($otherFloat) && $this->checkType($otherFloat);

        $clone = clone $this;
        $clone->value += is_object($otherFloat) ? $otherFloat->value : $otherFloat;

        return $clone;
    }

    final public function sub(float|AbstractFloat $otherFloat): static
    {
        is_object($otherFloat) && $this->checkType($otherFloat);

        $clone = clone $this;
        $clone->value -= is_object($otherFloat) ? $otherFloat->value : $otherFloat;

        return $clone;
    }

    final public function subPercent(Percent|string $percent): self
    {
        $percent = $percent instanceof Percent ? $percent : Percent::string($percent);

        $clone = clone $this;
        $clone->value -= $percent->of($this->value);

        return $clone;
    }

    final public function addPercent(Percent|string $percent): self
    {
        $percent = $percent instanceof Percent ? $percent : Percent::string($percent);

        $clone = clone $this;
        $clone->value += $percent->of($this->value);

        return $clone;
    }

    public function getPercentPart(Percent $percent): AbstractFloat
    {
        $clone = clone $this;
        $clone->value = $percent->of($this->value);

        return $clone;
    }

    private function checkType(AbstractFloat $otherFloat): void
    {
        $staticClass = static::class;
        $otherClass = get_class($otherFloat);

        if ($staticClass !== $otherClass) {
            throw new LogicException(
                sprintf('%s: subtracted value must be instance of %s (%s given)', self::class, $staticClass, $otherClass)
            );
        }
    }
}
