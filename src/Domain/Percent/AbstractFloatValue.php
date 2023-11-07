<?php

declare(strict_types=1);

namespace App\Domain\Percent;

use App\Domain\Percent\ValueObject\Percent;

abstract class AbstractFloatValue implements PercentModifiableValue
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

    /**
     * @todo Move to abstract implementation (and `addPercent` too?)
     */
    public function add(AbstractFloatValue $otherFloat): static
    {
        $clone = clone $this;
        $clone->value += $otherFloat->value;

        return $clone;
    }

    public function sub(AbstractFloatValue $otherFloat): static
    {
        $clone = clone $this;
        $clone->value -= $otherFloat->value;

        return $clone;
    }

    public function addPercent(Percent $percent): self
    {
        $clone = clone $this;
        $clone->value += $percent->of($this->value);

        return $clone;
    }
}
