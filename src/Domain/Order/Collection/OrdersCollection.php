<?php

declare(strict_types=1);

namespace App\Domain\Order\Collection;

use App\Domain\Order\Order;
use IteratorAggregate;
use Traversable;

/**
 * @template-implements IteratorAggregate<Order>
 */
final class OrdersCollection implements OrdersCollectionInterface
{
    private array $orders;

    public function __construct(Order ...$orders)
    {
        $this->orders = $orders;
    }

    public function merge(OrdersCollectionInterface $other): self
    {
        $this->orders = array_merge($this->orders, $other->getOrders());

        return $this;
    }

    /**
     * @return Order[]
     */
    public function getOrders(): array
    {
        return $this->orders;
    }

    public function getIterator(): Traversable
    {
        foreach ($this->orders as $order) {
            yield $order;
        }
    }

    public function count(): int
    {
        return count($this->orders);
    }
}
