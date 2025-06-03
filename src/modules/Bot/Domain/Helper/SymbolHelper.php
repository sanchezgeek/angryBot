<?php

declare(strict_types=1);

namespace App\Bot\Domain\Helper;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;

final class SymbolHelper
{
    /**
     * @return string[]
     */
    public static function symbolsToRawValues(SymbolInterface ...$symbols): array
    {
        return array_map(static fn (SymbolInterface $symbol) => $symbol->value, $symbols);
    }

    /**
     * @return SymbolInterface[]
     */
    public static function rawSymbolsToValueObjects(string ...$symbolsRaw): array
    {
        return array_map(static fn (string $symbolRaw) => SymbolEnum::from($symbolRaw), $symbolsRaw);
    }
}
