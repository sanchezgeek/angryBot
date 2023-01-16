<?php

declare(strict_types=1);

namespace App\Delivery\Application\Services\Geo;

use App\Delivery\Integration\Yandex\Geo\Api as GeoApi;
use App\Delivery\Integration\Yandex\Geo\GeoObject;

final class GeoObjectProvider
{
    public function __construct(
        private readonly GeoApi $api,
    ) {
    }

    public function findGeoObject(string $address): ?GeoObject
    {
        $response = $this->api
            ->setQuery($address)
            ->setLimit(1)
            ->load()
            ->getResponse();

        if ($response->getFoundCount()) {
            return $response->getFirst();
        }

        return null;
    }
}
