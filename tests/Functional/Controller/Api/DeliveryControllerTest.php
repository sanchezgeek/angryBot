<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Controller\Api\DeliveryController;
use App\Delivery\Application\Service\Geo\DistanceCalculatorInterface;
use App\Delivery\Application\Service\Geo\GeoObject;
use App\Delivery\Application\Service\Geo\GeoObjectProviderInterface;
use App\Delivery\Domain\DeliveryRepository;
use App\Helper\Json;
use App\Tests\Fixture\DeliveryFixture;
use App\Tests\Mixin\DbFixtureTrait;
use App\Tests\PHPUnit\DbDependentTest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @see DeliveryController
 */
final class DeliveryControllerTest extends AbstractApiControllerTest implements DbDependentTest
{
    use DbFixtureTrait;

    /**
     * @see .env.test
     */
    private const DEPOT_ADDRESS = 'Москва, Сумской проезд, 11';

    private const URL = '/api/delivery-order-create';

    private const VALID_REQUEST_DATA = [
        'order_id' => 100500,
        'address' => 'Тверская 6',
    ];

    private ?DeliveryRepository $deliveryRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deliveryRepository = self::getContainer()->get(DeliveryRepository::class);
    }

    public function invalidRequestCases(): iterable
    {
        // common
        yield 'without required params' => [
            \array_diff_key(self::VALID_REQUEST_DATA, \array_flip(['order_id', 'address'])),
            [
                ['field' => 'order_id', 'message' => 'This value should not be blank.'],
                ['field' => 'address', 'message' => 'This value should not be blank.'],
            ],
            Response::HTTP_BAD_REQUEST,
        ];

        // order_id
        yield 'invalid order_id type' => [
            \array_merge(self::VALID_REQUEST_DATA, ['order_id' => '100500']),
            [
                [
                    'field' => 'order_id',
                    'message' => 'This value should be of type int.',
                ],
            ],
            Response::HTTP_BAD_REQUEST,
        ];

        yield 'negative order_id value' => [
            \array_merge(self::VALID_REQUEST_DATA, ['order_id' => -1]),
            [
                [
                    'field' => 'order_id',
                    'message' => 'This value should be greater than 0.',
                ],
            ],
            Response::HTTP_BAD_REQUEST,
        ];

        yield 'zero order_id value' => [
            \array_merge(self::VALID_REQUEST_DATA, ['order_id' => 0]),
            [
                [
                    'field' => 'order_id',
                    'message' => 'This value should be greater than 0.',
                ],
            ],
            Response::HTTP_BAD_REQUEST,
        ];

        // address
        yield 'invalid address type' => [
            \array_merge(self::VALID_REQUEST_DATA, ['address' => 100500]),
            [
                [
                    'field' => 'address',
                    'message' => 'This value should be of type string.',
                ],
            ],
            Response::HTTP_BAD_REQUEST,
        ];

        yield 'address too short' => [
            \array_merge(self::VALID_REQUEST_DATA, ['address' => "Тверс"]),
            [
                [
                    'field' => 'address',
                    'message' => 'This value is too short. It should have 6 characters or more.',
                ],
            ],
            Response::HTTP_BAD_REQUEST,
        ];

        yield 'address too long' => [
            \array_merge(self::VALID_REQUEST_DATA, ['address' => \str_repeat("Тверс", 50)]),
            [
                [
                    'field' => 'address',
                    'message' => 'This value is too long. It should have 200 characters or less.',
                ],
            ],
            Response::HTTP_BAD_REQUEST,
        ];
    }

    /**
     * @dataProvider invalidRequestCases
     */
    public function testCreateDeliveryWithInvalidRequest(array $params, array $errors, int $code): void
    {
        $this->client->request(Request::METHOD_POST, self::URL, [], [], [], Json::encode($params));

        $this->checkResponseCodeAndContent($code, ['errors' => $errors]);
    }

    public function testCreateOrderDeliveryWhenDeliveryForThatOrderAlreadyExists(): void
    {
        // Arrange
        $existedDeliveryId = 100500;
        $orderId = 200500;

        $this->applyDbFixtures(new DeliveryFixture($existedDeliveryId, $orderId));

        $request = Json::encode(['order_id' => $orderId, 'address' => 'some new address']);

        // Act
        $this->client->request(Request::METHOD_POST, self::URL, [], [], [], $request);

        // Assert
        $this->checkResponseCodeAndContent(400, ['errors' => [
            [
                'field' => 'order_id',
                'message' => 'Delivery for this order already exists.',
                'payload' => ['deliveryId' => $existedDeliveryId],
            ],
        ]]);

        $deliveries = $this->deliveryRepository->findAll();
        self::assertCount(1, $deliveries);
        self::assertSame($existedDeliveryId, $deliveries[0]->getId());
        self::assertSame($orderId, $deliveries[0]->getOrderId());
        self::assertSame(DeliveryFixture::ADDRESS, $deliveries[0]->getAddress());
    }

    public function testCreateOrderDeliveryWhenDestinationNotFound(): void
    {
        // Arrange
        $request = Json::encode([
            'order_id' => $orderId = 100500,
            'address' => $deliveryAddress = 'Москва, Алтуфьевское шоссе, 58А',
        ]);

        $geoObjectProvider = $this->createMock(GeoObjectProviderInterface::class);
        $geoObjectProvider
            ->method('findGeoObject')
            ->withConsecutive([self::DEPOT_ADDRESS], [$deliveryAddress])
            ->willReturnOnConsecutiveCalls(
                new GeoObject('Сумской проезд, 11', 'Россия', 'Москва', 37.608975, 55.634954),
                null,
            );

        $distanceCalculator = $this->createMock(DistanceCalculatorInterface::class);
        $distanceCalculator->expects(self::never())->method('getDistanceBetween');

        self::getContainer()->set(GeoObjectProviderInterface::class, $geoObjectProvider);
        self::getContainer()->set(DistanceCalculatorInterface::class, $distanceCalculator);

        // Act
        $this->client->request(Request::METHOD_POST, self::URL, [], [], [], $request);

        // Assert
        self::assertCount(0, $this->deliveryRepository->findAll());

        $this->checkResponseCodeAndContent(400, ['errors' => [
            [
                'field' => 'address',
                'message' => \sprintf('Cannot find `%s` geo to calculate distance.', $deliveryAddress),
            ],
        ]]);
    }

    public function testSuccessCreateOrderDelivery(): void
    {
        // Arrange
        $request = Json::encode([
            'order_id' => $orderId = 100500,
            'address' => $deliveryAddress = 'Москва, Алтуфьевское шоссе, 58А',
        ]);

        $geoObjectProvider = $this->createMock(GeoObjectProviderInterface::class);
        $geoObjectProvider
            ->method('findGeoObject')
            ->withConsecutive([self::DEPOT_ADDRESS], [$deliveryAddress])
            ->willReturnOnConsecutiveCalls(
                $depot = new GeoObject('Сумской проезд, 11', 'Россия', 'Москва', 37.608975, 55.634954),
                $destination = new GeoObject('Алтуфьевское шоссе, 58А', 'Россия', 'Москва', 37.590075, 55.882005),
            );

        $distanceCalculator = $this->createMock(DistanceCalculatorInterface::class);
        $distanceCalculator
            ->expects(self::once())
            ->method('getDistanceBetween')
            ->with($depot, $destination)
            ->willReturn($distance = 27);

        self::getContainer()->set(GeoObjectProviderInterface::class, $geoObjectProvider);
        self::getContainer()->set(DistanceCalculatorInterface::class, $distanceCalculator);

        // Act
        self::assertCount(0, $this->deliveryRepository->findAll());
        $this->client->request(Request::METHOD_POST, self::URL, [], [], [], $request);

        // Assert
        $deliveries = $this->deliveryRepository->findAll();
        self::assertCount(1, $deliveries);
        self::assertSame($orderId, $deliveries[0]->getOrderId());
        self::assertSame($deliveryAddress, $deliveries[0]->getAddress());

        $this->checkResponseCodeAndContent(200, [
            'status' => 'Success',
            'payload' => [
                'deliveryId' => $deliveries[0]->getId(),
            ],
        ]);
    }
}
