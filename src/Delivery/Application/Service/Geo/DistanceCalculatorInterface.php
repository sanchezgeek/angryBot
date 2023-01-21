<?php

namespace App\Delivery\Application\Service\Geo;

interface DistanceCalculatorInterface
{
    public function getDistanceBetween(GeoObject $a, GeoObject $b): int;
}
