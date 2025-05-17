<?php

declare(strict_types=1);

namespace App\Command\Helper;

use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableCellStyle;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Output\OutputInterface;

class ConsoleTableHelper
{
    public static function cell(mixed $content, ?int $col = null, array $options = [], ?string $fontColor = null, ?string $backgroundColor = null, ?string $align = null): TableCell
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
        if ($wrapper === 'none') {
            return $text;
        }

        return sprintf('<%s>%s</%s>', $wrapper, $text, $wrapper);
    }

    public static function registerColors(OutputInterface $output): void
    {
        $output->getFormatter()->setStyle('bright-red-text', new OutputFormatterStyle(foreground: 'bright-red', options: ['blink']));
        $output->getFormatter()->setStyle('red-text', new OutputFormatterStyle(foreground: 'red', options: ['bold', 'blink']));
        $output->getFormatter()->setStyle('green-text', new OutputFormatterStyle(foreground: 'green', options: ['blink']));
        $output->getFormatter()->setStyle('yellow-text', new OutputFormatterStyle(foreground: 'yellow', options: ['bold', 'blink']));
        $output->getFormatter()->setStyle('light-yellow-text', new OutputFormatterStyle(foreground: 'yellow', options: ['blink']));
        $output->getFormatter()->setStyle('gray-text', new OutputFormatterStyle(foreground: 'gray', options: ['bold', 'blink']));
        $output->getFormatter()->setStyle('bright-white-text', new OutputFormatterStyle(foreground: 'bright-white'));
    }
}
