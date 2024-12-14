<?php

declare(strict_types=1);

namespace App\Domain\BuyOrder;

use App\Bot\Domain\Entity\BuyOrder;
use App\Domain\Price\PriceRange;
use App\Helper\VolumeHelper;
use IteratorAggregate;
use LogicException;

use function array_filter;
use function array_key_first;
use function sprintf;

/**
 * @see \App\Tests\Unit\Domain\BuyOrder\BuyOrdersCollectionTest
 *
 * @template-implements IteratorAggregate<BuyOrder>
 */
final class BuyOrdersCollection implements IteratorAggregate
{
    /** @var BuyOrder[] */
    private array $items = [];

    public function __construct(BuyOrder ...$items)
    {
        foreach ($items as $item) {
            $this->add($item);
        }
    }

    public function add(BuyOrder $buyOrder): self
    {
        if (isset($this->items[$buyOrder->getId()])) {
            throw new \LogicException(sprintf('BuyOrder with id "%d" was added before.', $buyOrder->getId()));
        }

        $this->items[$buyOrder->getId()] = $buyOrder;

        return $this;
    }

    public function remove(BuyOrder $buyOrder): self
    {
        if (!$this->has($buyOrder->getId())) {
            throw new LogicException(sprintf('BuyOrder with id "%d" not found.', $buyOrder->getId()));
        }

        unset($this->items[$buyOrder->getId()]);

        return $this;
    }

    public function has(int $id): bool
    {
        return isset($this->items[$id]);
    }

    public function getIterator(): \Generator
    {
        foreach ($this->items as $item) {
            yield $item;
        }
    }

    public function totalCount(): int
    {
        return count($this->items);
    }

    public function filterWithCallback(callable $callback): self
    {
        return new self(...array_filter($this->items, $callback));
    }

    public function totalVolume(): float
    {
        $volume = 0;
        foreach ($this->items as $item) {
            $volume += $item->getVolume();
        }

        return $volume > 0 ? $this->items[array_key_first($this->items)]->getSymbol()->roundVolume($volume) : 0;
    }

    public function grabFromRange(PriceRange $range): self
    {
        $buyOrders = new self();

        foreach ($this->items as $item) {
            if ($range->isPriceInRange($item->getPrice())) {
                $buyOrders->add($item);
            }
        }

        return $buyOrders;
    }
}
