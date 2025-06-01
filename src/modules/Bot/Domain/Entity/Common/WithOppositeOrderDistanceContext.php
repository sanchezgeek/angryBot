<?php

declare(strict_types=1);

namespace App\Bot\Domain\Entity\Common;

trait WithOppositeOrderDistanceContext
{
    public const string OPPOSITE_ORDERS_DISTANCE_CONTEXT = 'oppositeOrdersDistance';

    public function getOppositeOrderDistance(): ?float
    {
        return $this->context[self::OPPOSITE_ORDERS_DISTANCE_CONTEXT] ?? null;
    }

    public function setOppositeOrdersDistance(float $distance): self
    {
        $this->context[self::OPPOSITE_ORDERS_DISTANCE_CONTEXT] = $distance;

        return $this;
    }
}
