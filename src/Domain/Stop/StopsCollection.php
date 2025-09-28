<?php

declare(strict_types=1);

namespace App\Domain\Stop;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\PriceRange;
use App\Infrastructure\Exception\UnexpectedValueException;
use App\Trading\Domain\Symbol\SymbolInterface;
use Exception;
use IteratorAggregate;
use LogicException;

use function array_filter;
use function array_key_first;
use function array_map;
use function array_sum;
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

    public function __construct(Stop ...$stops)
    {
        foreach ($stops as $stop) {
            $this->add($stop);
        }
    }

    public function add(Stop $stop): self
    {
        $key = self::getKey($stop);

        if (isset($this->items[$key])) {
            if ($stopId = self::getStopId($stop)) {
                throw new \LogicException(sprintf('Stop with id "%d" was added before.', $stopId));
            } else {
                throw new \LogicException(sprintf('Stop with hash "%s" was added before.', $key));
            }
        }

        $this->items[$key] = $stop;

        return $this;
    }

    public function remove(Stop $stop): self
    {
        $key = self::getKey($stop);

        if (!isset($this->items[$key])) {
            throw new LogicException('Stop with not found.');
        }

        unset($this->items[$key]);

        return $this;
    }

    /**
     * @return Stop[]
     */
    public function getItems(): array
    {
        return $this->mapToIds($this->items);
    }

    public function getIterator(): \Generator
    {
        foreach ($this->items as $item) {
            yield $item;
        }
    }

    public function getMinPrice(): float
    {
        $prices = $this->getItemsPrices();

        return min($prices);
    }

    public function getMaxPrice(): float
    {
        $prices = $this->getItemsPrices();

        return max($prices);
    }

    public function getAvgPrice(): float
    {
        $prices = $this->getItemsPrices();

        return array_sum($prices) / count($prices);
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

    public function grabBySymbolAndSide(SymbolInterface $symbol, ?Side $side = null): array
    {
        return $this->mapToIds(
            array_filter($this->items, static fn(Stop $stop) => $stop->getSymbol()->eq($symbol) && (!$side || $stop->getPositionSide() === $side))
        );
    }

    /**
     * @throws UnexpectedValueException
     */
    public function getOneById(int $id): Stop
    {
        foreach ($this->items as $item) {
            if ($item->getId() === $id) {
                return $item;
            }
        }

        throw new Exception(sprintf('Cannot find stop by id=%d', $id));
    }

    private function getItemsPrices(): array
    {
        return array_map(static fn(Stop $stop) => $stop->getPrice(), $this->items);
    }

    private static function getKey(Stop $stop): string
    {
        return spl_object_hash($stop);
    }

    private static function getStopId(Stop $stop): ?int
    {
        try {
            return $stop->getId();
        } catch (UnexpectedValueException) {
            return null;
        }
    }

    private function mapToIds(array $items): array
    {
        $result = [];
        foreach ($items as $key => $item) {
            if ($id = self::getStopId($item)) {
                $result[$id] = $item;
            } else {
                $result[$key] = $item;
            }
        }

        return $result;
    }
}
