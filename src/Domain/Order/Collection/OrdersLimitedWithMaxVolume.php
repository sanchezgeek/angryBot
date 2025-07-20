<?php

declare(strict_types=1);

namespace App\Domain\Order\Collection;

use App\Domain\Order\Order;
use IteratorAggregate;
use Traversable;

/**
 * @template-implements IteratorAggregate<Order>
 */
final class OrdersLimitedWithMaxVolume implements OrdersCollectionInterface
{
    private null|array $orders = null;

    public function __construct(
        private readonly OrdersCollectionInterface $ordersCollection,
        private readonly float $maxVolume
    ) {
    }

    /**
     * @return Order[]
     */
    public function getOrders(): array
    {
        if ($this->orders !== null) {
            return $this->orders;
        }

        $volumeSum = 0;
        $maxVolume = $this->maxVolume;
        $orders = []; /** @var Order[] $orders */
        foreach ($this->ordersCollection as $order) {
            if ($volumeSum >= $maxVolume) {
                break;
            }

            $orderVolume = $order->volume();
            $orderPrice = $order->price();

            if (
                $orders
                && ($volumeLeft = $maxVolume - $volumeSum) < $orderVolume
            ) {
                $lastOrder = $orders[array_key_last($orders)];

                $context = array_merge($lastOrder->context(), $order->context());

                $orders[array_key_last($orders)] = new Order($lastOrder->price(), $lastOrder->volume() + $volumeLeft, $context);
                break;
            }
            $volumeSum += $orderVolume;

            $orders[] = new Order($orderPrice, $orderVolume, $order->context());
        }

        return $this->orders = $orders;
    }

    public function getIterator(): Traversable
    {
        foreach ($this->getOrders() as $order) {
            yield $order;
        }
    }

    public function count(): int
    {
        return count($this->getOrders());
    }

    public function totalVolume(): float
    {
        $totalVolume = 0;
        foreach ($this->getOrders() as $order) {
            $totalVolume += $order->volume();
        }

        return $totalVolume;
    }
}
