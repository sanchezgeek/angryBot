<?php

declare(strict_types=1);

namespace App\Command\Helper;

use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableCellStyle;

class ConsoleTableHelper
{
    public static function cell(mixed $content, int $col = null, array $options = [], string $fontColor = null, string $backgroundColor = null, string $align = null): TableCell
    {
        $style = [];
        if ($col) $options['colspan'] = $col;
        if ($fontColor) $style['fg'] = $fontColor;
        if ($backgroundColor) $style['bg'] = $backgroundColor;
        if ($align) $style['align'] = $align;

        if ($style) {
            $options['style'] = new TableCellStyle($style);
        }

        return new TableCell((string)$content, $options);
    }
}
