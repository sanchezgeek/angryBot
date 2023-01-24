<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\Exception\BadRequestException;
use App\Api\Request\DataFilterTrait;
use App\Api\Response\SuccessResponseDto;
use App\Delivery\Application\Exception\DeliveryDestinationNotFound;
use App\Delivery\Application\Service\OrderDelivery;
use App\Delivery\Application\Service\OrderDeliveryService;
use App\Delivery\Domain\Exception\OrderDeliveryAlreadyExists;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Type;

/**
 * @see \App\Tests\Functional\Controller\Api\DeliveryControllerTest
 */
class DeliveryController
{
    use DataFilterTrait;

    public function __construct(
        private readonly OrderDeliveryService $orderDeliveryService,
    ) {
    }

    /**
     * @Route("/api/delivery-order-create", methods={"POST"}, name="delivery-order-create")
     *
     * @param Request $request
     * @return JsonResponse
     * @throws BadRequestException
     */
    #[Route('/api/delivery-order-create', name: 'delivery-order-create', methods: 'POST')]
    public function create(Request $request): JsonResponse
    {
        [$orderId, $address] = self::filterData([
            'order_id' => [new NotBlank(), new Type('int'), new GreaterThan(0)],
            'address' => [new NotBlank(), new Type('string'), new Length(null, 6, 200)],
        ], $request->toArray());

        try {
            $deliveryId = $this->orderDeliveryService->create(new OrderDelivery($orderId, $address));
        } catch (OrderDeliveryAlreadyExists $e) {
            throw BadRequestException::errors([
                [
                    'field' => 'order_id',
                    'message' => $e->getMessage(),
                    'payload' => ['deliveryId' => $e->deliveryId],
                ],
            ]);
        } catch (DeliveryDestinationNotFound $e) {
            throw BadRequestException::error($e->getMessage(), 'address');
        }

        return new JsonResponse(
            new SuccessResponseDto(['deliveryId' => $deliveryId]),
        );
    }
}
