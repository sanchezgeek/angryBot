<?php

declare(strict_types=1);

namespace App\Delivery\Application\Service;

final readonly class OrderDelivery
{
    public function __construct(
        public int $orderId,
        public string $address,
    ) {
    }
}
