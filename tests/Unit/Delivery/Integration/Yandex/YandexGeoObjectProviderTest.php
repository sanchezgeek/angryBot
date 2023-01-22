<?php

declare(strict_types=1);

namespace App\Tests\Unit\Delivery\Integration\Yandex;

use App\Delivery\Application\Service\Geo\GeoObject;
use App\Delivery\Integration\Yandex\YandexGeoObjectProvider;
use App\Tests\Mock\Yandex\YandexGeocoderMockResponseFactory;
use App\Tests\Stub\Request\RequestCall;
use App\Tests\Stub\Request\SymfonyHttpClientStub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @see YandexGeoObjectProvider
 */
final class YandexGeoObjectProviderTest extends TestCase
{
    private const API_KEY = '1234567890';
    private const URL = 'https://geocode-maps.yandex.ru/1.x/';

    private SymfonyHttpClientStub $httpClient;
    private YandexGeoObjectProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = new SymfonyHttpClientStub();
        $this->provider = new YandexGeoObjectProvider($this->httpClient, self::API_KEY);
    }

    public function findGeoObjectTestCases(): iterable
    {
        yield 'found' => [
            'Москва, Сумской проезд 10',
            YandexGeocoderMockResponseFactory::found(),
            new GeoObject('Россия, Москва, Сумской проезд, 10', 'Россия', 'Москва', 37.608975, 55.634954),
        ];

        yield 'not found' => [
            'Unknown address',
            YandexGeocoderMockResponseFactory::notFound(),
            null,
        ];
    }

    /**
     * @dataProvider findGeoObjectTestCases
     */
    public function testFindGeoObject(string $address, MockResponse $yandexResponse, ?GeoObject $expectedGeoObject): void
    {
        // Arrange
        $this->httpClient->matchGet(self::URL, $query = [
            'apikey' => self::API_KEY,
            'geocode' => $address,
            'format' => 'json',
            'results' => 1,
        ], $yandexResponse);

        // Act
        $geoObject = $this->provider->findGeoObject($address);

        // Assert
        self::assertEquals($expectedGeoObject, $geoObject);

        self::assertEquals(
            [RequestCall::get(self::URL, $query)],
            $this->httpClient->getRequestCalls(),
        );
    }

    public function testUnauthorizedException(): void
    {
        // Arrange
        $this->httpClient->matchGet(self::URL, $query = [
            'apikey' => self::API_KEY,
            'geocode' => 'some-address',
            'format' => 'json',
            'results' => 1,
        ], YandexGeocoderMockResponseFactory::unauthorized());

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage(
            \sprintf(
                'Error in %s::findGeoObject: Invalid status code 403 (Forbidden: Invalid key). Request was: %s.',
                YandexGeoObjectProvider::class,
                self::URL . '?' . \http_build_query($query),
            ),
        );

        // Act / assert
        try {
            $this->provider->findGeoObject('some-address');
        } catch (\Exception $e) {
            self::assertEquals(
                [RequestCall::get(self::URL, $query)],
                $this->httpClient->getRequestCalls(),
            );

            throw $e;
        }
    }
}
