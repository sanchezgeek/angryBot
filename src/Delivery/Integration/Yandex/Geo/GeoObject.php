<?php

declare(strict_types=1);

namespace App\Delivery\Integration\Yandex\Geo;

final class GeoObject
{
    protected array $data;

    public function __construct(array $rawData)
    {
        $data = [
            'Address' => $rawData['metaDataProperty']['GeocoderMetaData']['text'],
            'Kind' => $rawData['metaDataProperty']['GeocoderMetaData']['kind']
        ];

        array_walk_recursive(
            $rawData,
            function ($value, $key) use (&$data) {
                if (in_array(
                    $key,
                    [
                        'CountryName',
                        'CountryNameCode',
                        'AdministrativeAreaName',
                        'SubAdministrativeAreaName',
                        'LocalityName',
                        'DependentLocalityName',
                        'ThoroughfareName',
                        'PremiseNumber',
                    ]
                )) {
                    $data[$key] = $value;
                }
            }
        );

        if (isset($rawData['Point']['pos'])) {
            $pos = explode(' ', $rawData['Point']['pos']);
            $data['Longitude'] = (float)$pos[0];
            $data['Latitude'] = (float)$pos[1];
        }

        $this->data = $data;
    }

    public function getLatitude(): ?float
    {
        return $this->data['Latitude'] ?? null;
    }

    public function getLongitude(): ?float
    {
        return $this->data['Longitude'] ?? null;
    }

    public function getCountry(): ?string
    {
        return $this->data['CountryName'] ?? null;
    }

    public function getCity(): ?string
    {
        return $this->data['LocalityName'] ?? null;
    }
}
