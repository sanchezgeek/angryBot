<?php

declare(strict_types=1);

namespace App\Delivery\Domain\Exception;

final class OrderDeliveryAlreadyExists extends \RuntimeException
{
    private function __construct(public readonly int $deliveryId)
    {
        parent::__construct('Delivery for this order already exists.');
    }

    public static function withDeliveryId(int $deliveryId): self
    {
        return new self($deliveryId);
    }
}
