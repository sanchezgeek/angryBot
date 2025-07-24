<?php

declare(strict_types=1);

namespace App\Bot\Domain\Entity\Common;

use App\Domain\Value\Percent\Percent;
use InvalidArgumentException;

trait WithOppositeOrderDistanceContext
{
    public const string OPPOSITE_ORDERS_DISTANCE_CONTEXT = 'oppositeOrdersDistance';

    public function setOppositeOrdersDistance(float|Percent $distance): self
    {
        $this->context[self::OPPOSITE_ORDERS_DISTANCE_CONTEXT] = $distance instanceof Percent ? (string)$distance : $distance;

        return $this;
    }

    public function isOppositeOrderDistanceSet(): bool
    {
        return isset($this->context[self::OPPOSITE_ORDERS_DISTANCE_CONTEXT]);
    }

    public function getOppositeOrderDistance(): float|Percent|null
    {
        if (!$this->isOppositeOrderDistanceSet()) {
            return null;
        }

        $value = $this->context[self::OPPOSITE_ORDERS_DISTANCE_CONTEXT];

        if (is_float($value) || is_int($value)) {
            return (float)$value;
        } else {
            return $value instanceof Percent ? $value : Percent::notStrict($this->fetchPercentValue($value));
        }
    }

    private function fetchPercentValue(string $value): float
    {
        if (
            !str_ends_with($value, '%')
            || (!is_numeric(substr($value, 0, -1)))
        ) {
            throw new InvalidArgumentException(
                sprintf('Invalid oppositeOrdersDistance (PNL%%) provided ("%s" given).', $value)
            );
        }

        return (float)substr($value, 0, -1);
    }
}
