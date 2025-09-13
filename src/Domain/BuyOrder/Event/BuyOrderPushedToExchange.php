<?php

declare(strict_types=1);

namespace App\Domain\BuyOrder\Event;

use App\Bot\Domain\Entity\BuyOrder;
use App\EventBus\Event;

final class BuyOrderPushedToExchange implements Event
{
    public function __construct(public BuyOrder $buyOrder)
    {
    }
}
