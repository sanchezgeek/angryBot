<?php

declare(strict_types=1);

namespace App\Command;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('command.positionService_dependent')]
interface PositionDependentCommand extends SymbolDependentCommand
{
    public function withPositionService(PositionServiceInterface $positionService): static;
}
