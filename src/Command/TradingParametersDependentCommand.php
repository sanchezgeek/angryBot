<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('command.trading_parameters_dependent')]
interface TradingParametersDependentCommand
{
}
