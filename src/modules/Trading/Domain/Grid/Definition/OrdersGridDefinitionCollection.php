<?php

declare(strict_types=1);

namespace App\Trading\Domain\Grid\Definition;

use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;
use App\Trading\Domain\Symbol\SymbolInterface;
use IteratorAggregate;
use Traversable;

/**
 * @template-implements IteratorAggregate<OrdersGridDefinition>
 */
final readonly class OrdersGridDefinitionCollection implements IteratorAggregate
{
    public const string SEPARATOR = ';';

    private array $items;

    public function __construct(
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

        return new self(...$items);
    }

    public function getIterator(): Traversable
    {
        foreach ($this->items as $item) {
            yield $item;
        }
    }
}
