<?php

declare(strict_types=1);

namespace App\Output\Table\Dto\Style;

use App\Output\Table\Dto\Style\Enum\Color;

final class RowStyle
{
    public function __construct(
        public bool   $separated = false,
        public ?Color $fontColor = null,
        public ?Color $backgroundColor = null,
    ) {
    }

    public static function default(): self
    {
        return new self(false);
    }

    public static function separated(): self
    {
        return new self(true);
    }

    public static function yellowFont(): self
    {
        return new self(false, Color::YELLOW);
    }

    public static function redFont(): self
    {
        return new self(false, Color::RED);
    }

    public function merge(RowStyle $other): self
    {
        $this->fontColor = $other->fontColor;
        $this->backgroundColor = $other->backgroundColor;

        return $this;
    }

//    public function setSeparated(bool $separated): self
//    {
//        $this->separated = $separated;
//        return $this;
//    }
}
