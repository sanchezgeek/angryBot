<?php

declare(strict_types=1);

namespace App\Delivery\Application\Command;

use App\Delivery\Application\Command\CreateOrderDelivery;
use App\Delivery\Domain\Delivery;
use App\Delivery\Domain\DeliveryRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CreateOrderDeliveryHandler
{
    public function __construct(
        private readonly DeliveryRepository $repository,
    ) {
    }

    public function __invoke(CreateOrderDelivery $command): void
    {
        $delivery = new Delivery($command->id);

        $delivery->setOrderId($command->orderId);
        $delivery->setAddress($command->address);

        $this->repository->save($delivery);
    }
}
