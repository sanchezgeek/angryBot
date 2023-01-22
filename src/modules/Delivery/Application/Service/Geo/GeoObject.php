<?php

declare(strict_types=1);

namespace App\Delivery\Application\Service\Geo;

final readonly class GeoObject
{
    public function __construct(
        public string $address,
        public string $country,
        public string $city,
        public float $longitude,
        public float $latitude,
    ) {
    }
}
