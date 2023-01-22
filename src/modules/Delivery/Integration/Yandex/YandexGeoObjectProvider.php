<?php

declare(strict_types=1);

namespace App\Delivery\Integration\Yandex;

use App\Delivery\Application\Service\Geo\GeoObject;
use App\Delivery\Application\Service\Geo\GeoObjectProviderInterface;
use App\Helper\Json;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @see \App\Tests\Unit\Delivery\Integration\Yandex\YandexGeoObjectProviderTest
 */
final class YandexGeoObjectProvider implements GeoObjectProviderInterface
{
    private const URL = 'https://geocode-maps.yandex.ru/1.x/';

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $yandexApiKey,
    ) {
    }

    public function findGeoObject(string $address): ?GeoObject
    {
        $query = [
            'apikey' => $this->yandexApiKey,
            'geocode' => $address,
            'format' => 'json',
            'results' => 1,
        ];

        $url = self::URL . '?' . \http_build_query($query);

        try {
            $response = $this->client->request(Request::METHOD_GET, $url);
            $content = Json::decode($response->getContent(false));

            //            file_put_contents(__DIR__ . '/../../../../tests/Mock/Yandex/response/unathorized.json', $response->getContent(false));
            //            die;

            if ($response->getStatusCode() !== Response::HTTP_OK) {
                throw new \RuntimeException(
                    \sprintf(
                        'Invalid status code %s (%s: %s)',
                        $response->getStatusCode(),
                        $content['error'],
                        $content['message'],
                    ),
                );
            }

            if ($items = $content['response']['GeoObjectCollection']['featureMember'] ?? []) {
                return $this->buildGeoObject($items[0]['GeoObject']);
            }

            return null;
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                \sprintf('Error in %s: %s. Request was: %s.', __METHOD__, $e->getMessage(), $url),
            );
        }
    }

    private function buildGeoObject(array $entry): GeoObject
    {
        $data = [
            'Address' => $entry['metaDataProperty']['GeocoderMetaData']['text'],
        ];

        \array_walk_recursive($entry, function ($value, $key) use (&$data) {
            if (\in_array($key, ['CountryName', 'LocalityName'])) {
                $data[$key] = $value;
            }
        });

        $pos = \explode(' ', $entry['Point']['pos']);
        $data['Longitude'] = (float)$pos[0];
        $data['Latitude'] = (float)$pos[1];

        return new GeoObject(
            $data['Address'],
            $data['CountryName'],
            $data['LocalityName'],
            $data['Longitude'],
            $data['Latitude'],
        );
    }
}
