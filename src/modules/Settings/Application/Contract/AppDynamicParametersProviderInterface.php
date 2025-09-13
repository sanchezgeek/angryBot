<?php

declare(strict_types=1);

namespace App\Settings\Application\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('dynamicParameters.provider')]
interface AppDynamicParametersProviderInterface
{
}
