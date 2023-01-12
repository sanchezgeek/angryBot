<?php

declare(strict_types=1);

namespace App\Delivery;

final class DeliveryCostCalculator
{
    public function calculate(int $distance, DeliveryRange ...$ranges): int
    {
        if ($distance <= 0) {
            throw new \InvalidArgumentException('Distance must be greater than zero.');
        }

        $cost = 0;

        foreach ($ranges as $range) {
            $cost += $range->getAppearedDistanceCost($distance);
        }

        return $cost;
    }
}
