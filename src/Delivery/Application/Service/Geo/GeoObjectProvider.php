<?php

declare(strict_types=1);

namespace App\Delivery\Application\Service\Geo;

use App\Delivery\Integration\Yandex\Geo\Api as GeoApi;
use App\Delivery\Integration\Yandex\Geo\GeoObject;

final class GeoObjectProvider implements GeoObjectProviderInterface
{
    public function __construct(
        private readonly GeoApi $api,
    ) {
    }

    public function findGeoObject(string $address): ?GeoObject
    {
        $result = $this->api
            ->setQuery($address)
            ->setLimit(1)
            ->load()
            ->getResult();

        if ($result->getCount()) {
            return $result->getFirst();
        }

        return null;
    }
}
