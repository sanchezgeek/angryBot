<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Delivery\Domain\Delivery;
use Doctrine\ORM\EntityManagerInterface;

final class DeliveryFixture extends AbstractFixture
{
    public const ADDRESS = 'some very nice address...';

    public function __construct(
        private readonly int $id,
        private readonly int $orderId,
    ) {
    }

    protected function apply(EntityManagerInterface $em): void
    {
        $delivery = new Delivery($this->id);

        $delivery->setOrderId($this->orderId);
        $delivery->setAddress(self::ADDRESS);

        $em->persist($delivery);
        $em->flush();
    }
}
