<?php

declare(strict_types=1);

namespace App\Delivery\Integration\Yandex\Geo;

final class Response
{
    /**
     * @var GeoObject[]
     */
    protected array $list = [];

    protected array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
        if (isset($data['response']['GeoObjectCollection']['featureMember'])) {
            foreach ($data['response']['GeoObjectCollection']['featureMember'] as $entry) {
                $this->list[] = new GeoObject($entry['GeoObject']);
            }
        }
    }

    public function getFirst(): ?GeoObject
    {
        $result = null;

        if (count($this->list)) {
            $result = $this->list[0];
        }

        return $result;
    }

    public function getFoundCount(): int
    {
        $result = null;
        if (isset($this->data['response']['GeoObjectCollection']['metaDataProperty']['GeocoderResponseMetaData']['found'])) {
            $result = (int)$this->data['response']['GeoObjectCollection']['metaDataProperty']['GeocoderResponseMetaData']['found'];
        }
        return $result;
    }
}
