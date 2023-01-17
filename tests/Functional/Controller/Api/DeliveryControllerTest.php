<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Controller\Api\DeliveryController;
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

    private const URL = '/api/delivery-order-create';

    private const VALID_REQUEST_DATA = [
        'order_id' => 100500,
        'address' => 'Тверская 6',
    ];

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
            \array_merge(self::VALID_REQUEST_DATA, ['address' => str_repeat("Тверс", 50)]),
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
            ]
        ]]);

        /** @var DeliveryRepository $deliveryRepository */
        $deliveryRepository = self::getContainer()->get(DeliveryRepository::class);
        $deliveries = $deliveryRepository->findAll();
        self::assertCount(1, $deliveries);
        self::assertSame($existedDeliveryId, $deliveries[0]->getId());
        self::assertSame($orderId, $deliveries[0]->getOrderId());
        self::assertSame(DeliveryFixture::ADDRESS, $deliveries[0]->getAddress());
    }
}
