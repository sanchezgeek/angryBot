<?php

declare(strict_types=1);

namespace App\Bot\Domain\Helper;

use App\Bot\Domain\ValueObject\Symbol;

final class SymbolHelper
{
    /**
     * @return string[]
     */
    public static function symbolsToRawValues(Symbol ...$symbols): array
    {
        return array_map(static fn (Symbol $symbol) => $symbol->value, $symbols);
    }

    /**
     * @return Symbol[]
     */
    public static function rawSymbolsToValueObjects(string ...$symbolsRaw): array
    {
        return array_map(static fn (string $symbolRaw) => Symbol::from($symbolRaw), $symbolsRaw);
    }
}
