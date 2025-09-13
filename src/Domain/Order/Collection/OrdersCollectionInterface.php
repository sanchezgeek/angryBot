<?php

declare(strict_types=1);

namespace App\Domain\Order\Collection;

use App\Domain\Order\Order;
use Countable;
use IteratorAggregate;

/**
 * @template-implements IteratorAggregate<Order>
 */
interface OrdersCollectionInterface extends IteratorAggregate, Countable
{
    /** @return Order[] */
    public function getOrders(): array;
    public function totalVolume(): float;
}
