<?php

declare(strict_types=1);

namespace App\Domain\Value\Common;

use App\Domain\Value\Percent\Percent;
use App\Domain\Value\Percent\PercentModifiableValue;
use LogicException;

use function get_class;
use function sprintf;

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

    /**
     * @todo Move to abstract implementation (and `addPercent` too?)
     */
    public function add(AbstractFloat $otherFloat): static
    {
        $this->checkType($otherFloat);

        $clone = clone $this;
        $clone->value += $otherFloat->value;

        return $clone;
    }

    public function sub(AbstractFloat $otherFloat): static
    {
        $this->checkType($otherFloat);

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
