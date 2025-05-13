<?php

declare(strict_types=1);

namespace App\Output\Table\Dto;

use App\Output\Table\Dto\Style\CellStyle;
use App\Output\Table\Dto\Style\Enum\CellAlign;
use App\Output\Table\Dto\Style\Enum\Color;
use App\Output\Table\Dto\Style\RowStyle;

final class Cell
{
    public CellStyle $style;

    public function __construct(
        public mixed  $content,
        CellStyle     $style = null,
    ) {
        $this->style = $style ?? new CellStyle();
    }

    public static function default(string $content = ''): self
    {
        return new self($content);
    }

    public static function colspan(int $colspan, string $content = ''): self
    {
        return new self($content, new CellStyle(colspan: $colspan));
    }

    public static function align(CellAlign $align, string $content = ''): self
    {
        return new self($content, new CellStyle(align: $align));
    }

    public static function resetToDefaults(string $content = ''): self
    {
        return new self($content, CellStyle::resetToDefaults());
    }

    public static function restColumnsMerged(string $content = '', int $rightOffsetAfterMerge = null): self
    {
        $cell = self::default($content);
        $cell->style->setMustBeMergedToTableEnd();
        $cell->style->rightOffsetAfterMerge = $rightOffsetAfterMerge;

        return $cell;
    }

    public static function generalInfo(string $content, CellStyle $cellStyle = null): self
    {
        $cellStyle = $cellStyle ?? new CellStyle();
        $cellStyle->fontColor = Color::YELLOW;

        return new self($content, $cellStyle);
    }

    public function setColspan(int $colspan): self
    {
        $this->style->setColspan($colspan);

        return $this;
    }

    public function setAlign(CellAlign $align): self
    {
        $this->style->align = $align;

        return $this;
    }

    public function addStyle(CellStyle $style): self
    {
        $this->style = $this->style->merge($style);

        return $this;
    }

    public function replaceStyle(CellStyle $style): self
    {
        $this->style = $style;

        return $this;
    }
}
