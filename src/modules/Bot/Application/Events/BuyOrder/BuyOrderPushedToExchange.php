<?php

declare(strict_types=1);

namespace App\Bot\Application\Events\BuyOrder;

final readonly class BuyOrderPushedToExchange
{
    public function __construct(public int $orderId)
    {
    }
}
