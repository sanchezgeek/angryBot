<?php

declare(strict_types=1);

namespace App\Delivery\Domain\Event;

use App\EventBus\Event;

final class DeliveryAddressChanged implements Event
{
    public function __construct(public readonly int $deliveryId)
    {
    }
}
