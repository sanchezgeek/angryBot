<?php

declare(strict_types=1);

namespace App\Output\Table\Dto\Style;

use App\Output\Table\Dto\Style\Enum\CellAlign;
use App\Output\Table\Dto\Style\Enum\Color;

final class CellStyle
{
    public function __construct(
        public ?Color     $fontColor = null,
        public ?Color     $backgroundColor = null,
        public int        $colspan = 1,
        public ?CellAlign $align = null,
        public bool       $mustBeMergedToTableEnd = false,
        public ?int       $rightOffsetAfterMerge = null,
    ) {
    }

    public static function default(): self
    {
        return new self();
    }

    public static function resetToDefaults(): self
    {
        return new self(Color::DEFAULT, Color::DEFAULT);
    }

    public static function generalInfo(string $content): self
    {
        return new self(fontColor: Color::YELLOW);
    }

    public static function right(): self
    {
        return new self(align: CellAlign::RIGHT);
    }

    public static function center(): self
    {
        return new self(align: CellAlign::CENTER);
    }

    public function setColspan(int $colspan): self
    {
        $this->colspan = $colspan;
        return $this;
    }

    public function addColspan(int $colspan): self
    {
        $this->colspan += $colspan;

        return $this;
    }

    public function setMustBeMergedToTableEnd(): self
    {
        $this->mustBeMergedToTableEnd = true;

        return $this;
    }

    public function merge(CellStyle $other): self
    {
        $this->colspan = $other->colspan;
        $this->fontColor = $other->fontColor;
        $this->backgroundColor = $other->backgroundColor;
        $this->align = $other->align;

        return $this;
    }
}
