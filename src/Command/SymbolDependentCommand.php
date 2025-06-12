<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('command.symbol_dependent')]
interface SymbolDependentCommand
{
}
