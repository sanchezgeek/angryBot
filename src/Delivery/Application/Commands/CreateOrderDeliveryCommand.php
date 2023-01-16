<?php

declare(strict_types=1);

namespace App\Delivery\Application\Commands;

final class CreateOrderDeliveryCommand
{
    public function __construct(
        public readonly int $id,
        public readonly int $orderId,
        public readonly string $address
    ) {
    }
}
