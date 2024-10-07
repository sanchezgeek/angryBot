<?php

declare(strict_types=1);

namespace App\Output\Table\Dto;

use App\Output\Table\Dto\Style\RowStyle;
use IteratorAggregate;
use Traversable;

/**
 * @template-implements IteratorAggregate<int, Cell|string>
 */
final class DataRow implements RowInterface, IteratorAggregate
{
    /** @var Cell[]|string[] */
    private array $cells;
    public function __construct(
        public RowStyle $style,
        Cell|string|int|float ...$cells,
    ) {
        $this->cells = $cells;
    }

    public static function default(array $cells): self
    {
        return new self(RowStyle::default(), ...$cells);
    }

    public static function empty(): self
    {
        return new self(RowStyle::default());
    }

    public static function separated(array $cells, ?RowStyle $rowStyle = null): self
    {
        $specifiedStyle = $rowStyle ?? RowStyle::default();
        return new self(RowStyle::separated()->merge($specifiedStyle), ...$cells);
    }

    public function getIterator(): Traversable
    {
        foreach ($this->cells as $cell) {
            yield $cell;
        }
    }

    public function addStyle(RowStyle $style): self
    {
        $this->style = $this->style->merge($style);

        return $this;
    }

    public function replaceStyle(RowStyle $style): self
    {
        $this->style = $style;

        return $this;
    }
}
