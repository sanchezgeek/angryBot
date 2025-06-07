<?php

declare(strict_types=1);

namespace App\Trading\Application\UseCase\Symbol\InitializeSymbols;

use App\Domain\Coin\Coin;

final class InitializeSymbolsEntry
{
    public function __construct(
        public string $symbolName,
        public ?Coin $quoteCoin = null,
    ) {
    }
}
