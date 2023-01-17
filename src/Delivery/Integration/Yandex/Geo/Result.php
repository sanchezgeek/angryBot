<?php

declare(strict_types=1);

namespace App\Delivery\Integration\Yandex\Geo;

final class Result
{
    /**
     * @var GeoObject[]
     */
    protected array $items = [];

    public function __construct(array $data)
    {
        foreach ($data['response']['GeoObjectCollection']['featureMember'] ?? [] as $entry) {
            $this->items[] = $this->buildGeoObject($entry['GeoObject']);
        }
    }

    public function getFirst(): ?GeoObject
    {
        return $this->items[0] ?? null;
    }

    public function getCount(): int
    {
        return count($this->items);
    }

    private function buildGeoObject(array $entry): GeoObject
    {
        $data = [
            'Address' => $entry['metaDataProperty']['GeocoderMetaData']['text'],
        ];

        array_walk_recursive($entry, function ($value, $key) use (&$data) {
            if (in_array($key, ['CountryName', 'LocalityName'])) {
                $data[$key] = $value;
            }
        });

        $pos = explode(' ', $entry['Point']['pos']);
        $data['Longitude'] = (float)$pos[0];
        $data['Latitude'] = (float)$pos[1];

        return new GeoObject(
            $data['Address'],
            $data['CountryName'],
            $data['LocalityName'],
            $data['Longitude'],
            $data['Latitude']
        );
    }
}
