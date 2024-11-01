<?php

declare(strict_types=1);

namespace App\Domain\Order\Contract;

use App\Bot\Domain\ValueObject\Order\OrderType;

interface OrderTypeAwareInterface
{
    public function getOrderType(): OrderType;
}
