<?php

declare(strict_types=1);

namespace App\Domain\Stop;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Domain\Price\PriceRange;
use App\Helper\VolumeHelper;
use IteratorAggregate;
use LogicException;

use function array_filter;
use function array_map;
use function array_values;
use function sprintf;

/**
 * @see \App\Tests\Unit\Domain\Stop\StopsCollectionTest
 *
 * @template-implements IteratorAggregate<Stop>
 */
final class StopsCollection implements IteratorAggregate
{
    /** @var Stop[] */
    private array $items = [];

    public function __construct(Stop ...$items)
    {
        foreach ($items as $item) {
            $this->add($item);
        }
    }

    public function add(Stop $stop): self
    {
        if (isset($this->items[$stop->getId()])) {
            throw new \LogicException(sprintf('Stop with id "%d" was added before.', $stop->getId()));
        }

        $this->items[$stop->getId()] = $stop;

        return $this;
    }

    public function remove(Stop $stop): self
    {
        if (!$this->has($stop->getId())) {
            throw new LogicException(sprintf('Stop with id "%d" not found.', $stop->getId()));
        }

        unset($this->items[$stop->getId()]);

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

    public function getMinPrice(): float
    {
        $prices = array_map(static fn(Stop $stop) => $stop->getPrice(), $this->items);

        return min($prices);
    }

    public function getMaxPrice(): float
    {
        $prices = array_map(static fn(Stop $stop) => $stop->getPrice(), $this->items);

        return max($prices);
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

        return $volume > 0 ? VolumeHelper::round($volume) : 0;
    }

    public function totalUsdPnL(Position $forPosition): float
    {
        $total = 0;

        foreach ($this->items as $item) {
            $total += $item->getPnlUsd($forPosition);
        }

        return $total;
    }

    public function volumePart(float $volume): float
    {
        return round(($this->totalVolume() / $volume) * 100, 3);
    }

    public function grabFromRange(PriceRange $range): self
    {
        $stops = new self();

        foreach ($this->items as $item) {
            if ($range->isPriceInRange($item->getPrice())) {
                $stops->add($item);
            }
        }

        return $stops;
    }
}
