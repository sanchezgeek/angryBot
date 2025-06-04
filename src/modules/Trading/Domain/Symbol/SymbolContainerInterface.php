<?php

declare(strict_types=1);

namespace App\Trading\Domain\Symbol;

use App\Trading\Domain\Symbol\Entity\Symbol;

/**
 * @internal For tests
 */
interface SymbolContainerInterface
{
    public function getSymbol(): SymbolInterface;
    public function replaceSymbolEntity(Symbol $symbol): self;
}
