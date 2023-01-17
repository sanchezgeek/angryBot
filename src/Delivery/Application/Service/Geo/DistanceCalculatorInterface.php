<?php

namespace App\Delivery\Application\Service\Geo;

use App\Delivery\Integration\Yandex\Geo\GeoObject;

interface DistanceCalculatorInterface
{
    public function getDistanceBetween(GeoObject $a, GeoObject $b): int;
}
