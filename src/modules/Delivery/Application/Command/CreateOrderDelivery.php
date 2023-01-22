<?php

declare(strict_types=1);

namespace App\Delivery\Application\Command;

final class CreateOrderDelivery
{
    public function __construct(
        public readonly int $id,
        public readonly int $orderId,
        public readonly string $address,
    ) {
    }
}
