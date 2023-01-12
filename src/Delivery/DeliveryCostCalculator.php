<?php

declare(strict_types=1);

namespace App\Delivery;

final class DeliveryCostCalculator
{
    public function calculate($distance, DeliveryRange ...$ranges): int
    {
        $cost = 0;

        foreach ($ranges as $range) {
            $cost += $range->getAppearedDistanceCost($distance);
        }

        return $cost;
    }
}
