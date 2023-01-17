<?php

declare(strict_types=1);

namespace App\Delivery\Integration\Yandex\Geo;

final class GeoObject
{
    public function __construct(
        public readonly string $address,
        public readonly string $country,
        public readonly string $city,
        public readonly float $longitude,
        public readonly float $latitude,
    ) {
    }
}
