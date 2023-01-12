<?php

declare(strict_types=1);

namespace App\Delivery;

final class DeliveryRange
{
    private int $start;
    private ?int $end;
    private int $price;

    public function __construct(int $price, int $start, ?int $end)
    {
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
}
