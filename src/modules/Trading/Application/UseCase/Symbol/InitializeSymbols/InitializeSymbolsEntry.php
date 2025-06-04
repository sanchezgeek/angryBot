<?php

declare(strict_types=1);

namespace App\Trading\Application\UseCase\Symbol\InitializeSymbols;

final class InitializeSymbolsEntry
{
    public function __construct(
        public string $symbolName
    ) {
    }
}
