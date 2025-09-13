<?php

declare(strict_types=1);

namespace App\Trading\Domain\Grid\Definition;

use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\PriceRange;
use App\Domain\Price\SymbolPrice;
use App\Trading\Domain\Symbol\SymbolInterface;
use IteratorAggregate;
use Stringable;
use Traversable;

/**
 * @template-implements IteratorAggregate<OrdersGridDefinition>
 */
final class OrdersGridDefinitionCollection implements IteratorAggregate, Stringable
{
    public const string SEPARATOR = ';';

    private readonly array $items;

    private bool $foundAutomaticallyFromTa = false;

    public function __construct(
        private readonly SymbolInterface $symbol,
        private readonly string $definition,
        OrdersGridDefinition ...$items
    ) {
        $this->items = $items;
    }

    public static function create(string $collectionDefinition, SymbolPrice $refPrice, Side $positionSide, SymbolInterface $symbol): self
    {
        $items = [];
        foreach (explode(self::SEPARATOR, $collectionDefinition) as $child) {
            $items[] = OrdersGridDefinition::create($child, $refPrice, $positionSide, $symbol);
        }

        return new self($symbol, $collectionDefinition, ...$items);
    }

    public function getIterator(): Traversable
    {
        foreach ($this->items as $item) {
            yield $item;
        }
    }

    public function setFoundAutomaticallyFromTa(): self
    {
        $this->foundAutomaticallyFromTa = true;

        return $this;
    }

    public function isFoundAutomaticallyFromTa(): bool
    {
        return $this->foundAutomaticallyFromTa;
    }

    public function __toString()
    {
        return $this->definition;
    }

    public function factAbsoluteRange(): PriceRange
    {
        $from = [];
        $to = [];
        foreach ($this->items as $item) {
            $from[] = $item->priceRange->from();
            $to[] = $item->priceRange->to();
        }

        return PriceRange::create(min($from), max($to), $this->symbol);
    }
}
