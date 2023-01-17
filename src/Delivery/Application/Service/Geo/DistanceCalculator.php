<?php

declare(strict_types=1);

namespace App\Delivery\Application\Service\Geo;

use App\Delivery\Integration\Yandex\Geo\GeoObject;

final class DistanceCalculator implements DistanceCalculatorInterface
{
    /**
     * @return int Distance in meters
     */
    public function getDistanceBetween(GeoObject $a, GeoObject $b): int
    {
        return $this->calculateDistance($b->latitude, $b->longitude, $a->latitude, $a->longitude);
    }

    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): int
    {
        if ($lat1 === $lat2 && $lon1 === $lon2) {
            return 0;
        } else {
            $theta = $lon1 - $lon2;
            $dist =
                sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +
                cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
            $dist = acos($dist);
            $dist = rad2deg($dist);
            $miles = $dist * 60 * 1.1515;

            return (int)($miles * 1.609344);
        }
    }
}
