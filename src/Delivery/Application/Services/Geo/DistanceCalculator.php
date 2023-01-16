<?php

declare(strict_types=1);

namespace App\Delivery\Application\Services\Geo;

final class DistanceCalculator
{
    public function __construct(
        private readonly GeoObjectProvider $geoProvider,
    ) {
    }

    /**
     * @return int Distance in meters
     */
    public function getDistanceBetween(string $a, string $b): int
    {
        $aGeo = $this->geoProvider->findGeoObject($a);
        $bGeo = $this->geoProvider->findGeoObject($b);

        return $this->calculateDistance(
            $bGeo->getLatitude(),
            $bGeo->getLongitude(),
            $aGeo->getLatitude(),
            $aGeo->getLongitude(),
        );
    }

    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): int
    {
        if (($lat1 == $lat2) && ($lon1 == $lon2)) {
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
