<?php

declare(strict_types=1);

namespace App\Output\Table\Formatter;

use App\Command\Helper\ConsoleTableHelper as TH;
use App\Output\Table\Dto\Cell;
use App\Output\Table\Dto\DataRow;
use App\Output\Table\Dto\RowInterface;
use App\Output\Table\Dto\SeparatorRow;
use App\Output\Table\Dto\Style\CellStyle;
use App\Output\Table\Dto\Style\Enum\Color;
use App\Output\Table\Dto\Style\RowStyle;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Output\OutputInterface;

final class ConsoleTableBuilder
{
    private array $headerColumns = [];
    /** @var DataRow[] */
    private array $rows = [];

    private function __construct(private readonly OutputInterface $output)
    {
    }

    public static function withOutput(OutputInterface $output): self
    {
        return new self($output);
    }

    public function withHeader(array $headerColumns): self
    {
        $this->headerColumns = $headerColumns;
        return $this;
    }

    public function withRows(RowInterface ...$rows): self
    {
        $this->rows = $rows;

        return $this;
    }

    private int $columnsCount;

    public function build(): Table
    {
        $this->columnsCount = sizeof($this->headerColumns);

        $rows = [];
        $previousRow = null;
        $firstKey = array_key_first($this->rows);
        $lastKey = array_key_last($this->rows);
        foreach ($this->rows as $key => $row) {
            if ($row instanceof SeparatorRow) {
                $rows[] = new TableSeparator();
                continue;
            }

            ($row->style->separated && !$previousRow?->style->separated && $key !== $firstKey) && $rows[] = new TableSeparator();
            $rows = array_merge($rows, $this->formatDataRow($row));
            ($row->style->separated && $key !== $lastKey) && $rows[] = new TableSeparator();
            $previousRow = $row;
        }

        return (new Table($this->output))->setHeaders($this->headerColumns)->addRows($rows);
    }

    public function formatDataRow(DataRow $row): array
    {
        $cells = [];
        $currentFilledColumns = 0;
        foreach ($row as $inputCell) {
            $content = $inputCell instanceof Cell ? $inputCell->content : $inputCell;
            $cellStyle = $inputCell instanceof Cell ? $inputCell->style : CellStyle::default();
            $inputCell = $inputCell instanceof Cell ? $inputCell :  null;

            $mustBeMergedToTableEnd = $inputCell?->style->mustBeMergedToTableEnd;
            $rightOffset = $inputCell?->style->rightOffsetAfterMerge ?? 0;

            $colspan = !$mustBeMergedToTableEnd ? $cellStyle->colspan : $this->columnsCount - $currentFilledColumns - $rightOffset;
            $cells[] = TH::cell(
                $content,
                $colspan,
                [],
                self::rawColorToConsoleFontColor(self::cellColor($row, $inputCell, 'font')),
                self::rawColorToConsoleBgColor(self::cellColor($row, $inputCell, 'bg')),
                $cellStyle?->align?->value
            );

            $currentFilledColumns += $colspan;
        }

        $result[] = $cells;

        return $result;
    }

    public static function rawColorToConsoleFontColor(?Color $color): ?string
    {
        return match ($color) {
            null => null,
            Color::BRIGHT_GREEN => 'bright-green',
            Color::BRIGHT_RED => 'bright-red',
            Color::BRIGHT_MAGENTA => 'bright-magenta',
            Color::BRIGHT_WHITE => 'bright-white',
            default => !$color->isDefault() ? $color->name : null,
        };
    }

    public static function rawColorToConsoleBgColor(?Color $color): ?string
    {
        return match ($color) {
            null => null,
            Color::RED => 'red',
            Color::BRIGHT_GREEN => 'bright-green',
            Color::BRIGHT_RED => 'bright-red',
            Color::BRIGHT_WHITE => 'bright-white',
            default => !$color->isDefault() ? $color->name : null,
        };
    }

    public static function cellColor(DataRow $row, ?Cell $cell, string $type): ?Color
    {
        $getColor = static fn(RowStyle|CellStyle $style) => match ($type) {'font' => $style->fontColor, 'bg' => $style->backgroundColor};

        if ($cell && ($color = $getColor($cell->style))) {
            return $color;
        }

        if ($color = $getColor($row->style)) {
            return $color;
        }

        return null;
    }
}
