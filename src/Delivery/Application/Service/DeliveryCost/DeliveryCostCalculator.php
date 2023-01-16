<?php

declare(strict_types=1);

namespace App\Delivery\Application\Service\DeliveryCost;

use App\Delivery\Application\Service\DeliveryCost\DeliveryPriceRange;

/**
 * @see \App\Tests\Unit\Delivery\Application\Service\DeliveryCost\DeliveryCostCalculatorTest
 */
final class DeliveryCostCalculator
{
    /**
     * @throws \InvalidArgumentException
     */
    public function calculate(int $distance, DeliveryPriceRange ...$ranges): int
    {
        if ($distance <= 0) {
            throw new \InvalidArgumentException('Distance must be greater than zero.');
        }

        $this->validateRanges($ranges);

        $cost = 0;
        foreach ($ranges as $range) {
            $cost += $range->getAppearedDistanceCost($distance);
        }

        return $cost;
    }

    /**
     * @param DeliveryPriceRange[] $ranges
     * @throws \InvalidArgumentException
     */
    private function validateRanges(array $ranges): void
    {
        usort($ranges, function (DeliveryPriceRange $prev, DeliveryPriceRange $next): int {
            return $prev->getStart() - $next->getStart();
        });

        foreach ($ranges as $key => $range) {
            $mustStartsFrom = $key > 0 ? $ranges[$key - 1]->getEnd() : 0; // from previous range end or from 0 (if it's first range)
            $mustEndsOn = isset($ranges[$key + 1]) ? $ranges[$key + 1]->getStart() : null; // on next range start or must have no end

            if ($range->getStart() !== $mustStartsFrom || $range->getEnd() !== $mustEndsOn) {
                throw new \InvalidArgumentException(
                    'Check that the segments are specified correctly: maybe the segments intersect or there are gaps between segments.'
                );
            }
        }
    }
}
