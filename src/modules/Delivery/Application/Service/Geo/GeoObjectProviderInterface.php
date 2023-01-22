<?php

namespace App\Delivery\Application\Service\Geo;

interface GeoObjectProviderInterface
{
    public function findGeoObject(string $address): ?GeoObject;
}
