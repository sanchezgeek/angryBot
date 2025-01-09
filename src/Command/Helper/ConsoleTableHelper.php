<?php

declare(strict_types=1);

namespace App\Command\Helper;

use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableCellStyle;
use Symfony\Component\Console\Helper\TableSeparator;

class ConsoleTableHelper
{
    public static function cell(mixed $content, int $col = null, array $options = [], string $fontColor = null, string $backgroundColor = null, string $align = null): TableCell
    {
        $style = [];
        if ($col) $options['colspan'] = $col;
        if ($fontColor) $style['fg'] = $fontColor;
        if ($backgroundColor) $style['bg'] = $backgroundColor;
        if ($align) $style['align'] = $align;

        $style = array_filter($style);

        if ($style) {
            $options['style'] = new TableCellStyle($style);
        }

        return new TableCell((string)$content, $options);
    }

    public static function separator(): TableSeparator
    {
        return new TableSeparator();
    }

    public static function colorizeText(string $text, string $wrapper): string
    {
        return sprintf('<%s>%s</%s>', $wrapper, $text, $wrapper);
    }
}
