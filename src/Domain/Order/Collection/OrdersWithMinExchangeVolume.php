<?php

declare(strict_types=1);

namespace App\Domain\Order\Collection;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\ExchangeOrder;
use App\Domain\Order\Order;
use IteratorAggregate;
use Traversable;

/**
 * @template-implements IteratorAggregate<Order>
 */
final class OrdersWithMinExchangeVolume implements OrdersCollectionInterface
{
    private null|array $orders = null;

    public function __construct(
        private readonly Symbol $symbol,
        private readonly OrdersCollectionInterface $sourceOrdersCollection,
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

        $symbol = $this->symbol;
        $minVolume = $symbol->minOrderQty();

        foreach ($this->sourceOrdersCollection as $order) {
            $price = $order->price();
            $volume = max($order->volume(), $minVolume);

            $nominal = ExchangeOrder::roundedToMin($symbol, $volume, $price);
            if ($volume < ($minNotionalVolume = $nominal->getVolume())) {
                $volume = $minNotionalVolume;
            }

            $this->orders[] = new Order($price, $volume);
        }

        return $this->orders;
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
}
