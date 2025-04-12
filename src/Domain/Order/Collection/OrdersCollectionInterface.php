<?php

declare(strict_types=1);

namespace App\Domain\Order\Collection;

use App\Domain\Order\Order;
use Countable;
use IteratorAggregate;

interface OrdersCollectionInterface extends IteratorAggregate, Countable
{
    /** @return Order[] */
    public function getOrders(): array;
}
