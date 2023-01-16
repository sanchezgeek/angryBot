<?php

declare(strict_types=1);

namespace App\Delivery\Application\Services\DeliveryCost;

final class DeliveryPriceRange
{
    private int $start;
    private ?int $end;
    private int $price;

    public function __construct(int $start, ?int $end, int $price)
    {
        if ($end !== null && $end <= 0) {
            throw new \InvalidArgumentException(
                'The end of the segment must be greater than zero.'
            );
        }

        if ($start < 0) {
            throw new \InvalidArgumentException(
                'The beginning of the segment must be greater or equal to zero.'
            );
        }

        if ($end !== null && $end <= $start) {
            throw new \InvalidArgumentException(
                \sprintf('The end of the segment must be greater than start ("%s..%s").', $start, $end ?: '∞')
            );
        }

        if ($price < 0) {
            throw new \InvalidArgumentException(
                \sprintf('The price of the segment ("%s..%s") must be greater than 0.', $start, $end)
            );
        }

        $this->start = $start;
        $this->end = $end;
        $this->price = $price;
    }

    public function getAppearedDistanceCost(int $distance): int
    {
        if ($distance > $this->start) {
            $appearedDistance = min($this->end ?? $distance, $distance) - $this->start;

            return $appearedDistance * $this->price;
        }

        return 0;
    }

    public function getStart(): int
    {
        return $this->start;
    }

    public function getEnd(): ?int
    {
        return $this->end;
    }

    public function getPrice(): int
    {
        return $this->price;
    }
}
