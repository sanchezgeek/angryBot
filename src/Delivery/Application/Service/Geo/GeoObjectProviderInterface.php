<?php

namespace App\Delivery\Application\Service\Geo;

use App\Delivery\Integration\Yandex\Geo\GeoObject;

interface GeoObjectProviderInterface
{
    public function findGeoObject(string $address): ?GeoObject;
}
