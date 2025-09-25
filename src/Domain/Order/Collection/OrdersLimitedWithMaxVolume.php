<?php

declare(strict_types=1);

namespace App\Domain\Order\Collection;

use App\Domain\Order\Order;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\PriceRange;
use App\Trading\Domain\Symbol\SymbolInterface;
use App\Worker\AppContext;
use IteratorAggregate;
use RuntimeException;
use Traversable;

/**
 * @template-implements IteratorAggregate<Order>
 */
final class OrdersLimitedWithMaxVolume implements OrdersCollectionInterface
{
    private null|array $orders = null;

    public function __construct(
        private readonly OrdersCollectionInterface $ordersCollection,
        private readonly float $maxVolume,
        private readonly SymbolInterface $symbol,
        private readonly Side $positionSIde,
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

        /** @var Order[] $initialOrders */
        $initialOrders = iterator_to_array($this->ordersCollection);

        foreach ($initialOrders as $order) {
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

        if (count($initialOrders) !== count($orders)) {
            $first = $initialOrders[array_key_first($initialOrders)];
            $last = $initialOrders[array_key_last($initialOrders)];
            $priceRange = PriceRange::create($first->price(), $last->price(), $this->symbol);

            foreach ($priceRange->byQntIterator(count($orders), $this->positionSIde) as $key => $price) {
                if (!isset($orders[$key])) {
                    throw new RuntimeException(sprintf('Cannot found order by offset "%s"', $key));
                }
                $orders[$key] = $orders[$key]->replacePrice($price);
            }
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
