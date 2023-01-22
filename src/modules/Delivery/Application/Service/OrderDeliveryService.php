<?php

declare(strict_types=1);

namespace App\Delivery\Application\Service;

use App\Delivery\Application\Command\CreateOrderDelivery;
use App\Delivery\Application\Exception\DeliveryDestinationNotFound;
use App\Delivery\Domain\DeliveryRepository;
use App\Delivery\Domain\Exception\OrderDeliveryAlreadyExists;
use App\Trait\DispatchCommandTrait;
use Symfony\Component\Messenger\MessageBusInterface;

final class OrderDeliveryService
{
    use DispatchCommandTrait;

    public function __construct(
        private readonly DeliveryRepository $repository,
        MessageBusInterface $commandBus,
    ) {
        $this->commandBus = $commandBus;
    }

    /**
     * @throws OrderDeliveryAlreadyExists
     * @throws DeliveryDestinationNotFound
     */
    public function create(OrderDelivery $orderDelivery): int
    {
        if ($delivery = $this->repository->findOneByOrderId($orderDelivery->orderId)) {
            throw OrderDeliveryAlreadyExists::withDeliveryId($delivery->getId());
        }

        $deliveryId = $this->repository->getNextId();

        $this->dispatchCommand(
            new CreateOrderDelivery(
                $deliveryId,
                $orderDelivery->orderId,
                $orderDelivery->address,
            ),
        );

        return $deliveryId;
    }
}
