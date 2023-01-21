<?php

declare(strict_types=1);

namespace App\Tests\Unit\Delivery\Integration\Yandex;

use App\Delivery\Application\Service\Geo\GeoObject;
use App\Delivery\Integration\Yandex\YandexGeoObjectProvider;
use App\Tests\Mock\Yandex\YandexGeocoderMockResponseFactory;
use App\Tests\Stub\SymfonyHttpClientStub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @see YandexGeoObjectProvider
 */
final class YandexGeoObjectProviderTest extends TestCase
{
    private const API_KEY = '1234567890';

    public function findGeoObjectTestCases(): iterable
    {
        yield 'found' => [
            'Москва, Сумской проезд 10',
            YandexGeocoderMockResponseFactory::found(),
            new GeoObject('Россия, Москва, Сумской проезд, 10', 'Россия', 'Москва', 37.608975, 55.634954),
        ];
    }

    /**
     * @dataProvider findGeoObjectTestCases
     */
    public function testFindGeoObject(string $address, MockResponse $yandexResponse, GeoObject $expectedGeoObject): void
    {
        // Arrange
        $query = [
            'apikey' => self::API_KEY,
            'geocode' => $address,
            'format' => 'json',
            'results' => 1,
        ];

        $httpClient = new SymfonyHttpClientStub();
        $httpClient->matchGet('https://geocode-maps.yandex.ru/1.x/', $query, $yandexResponse);

        $provider = new YandexGeoObjectProvider($httpClient, self::API_KEY);

        // Act
        $geoObject = $provider->findGeoObject($address);

        // Assert
        self::assertEquals(1, $httpClient->getCallsCount());
        self::assertEquals('https://geocode-maps.yandex.ru/1.x/', $httpClient->getCallRequestUrl(0));
        self::assertEquals($query, $httpClient->getCallRequestParams(0));

        self::assertEquals($expectedGeoObject, $geoObject);
    }
}
