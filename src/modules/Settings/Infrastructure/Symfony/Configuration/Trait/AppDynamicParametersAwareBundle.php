<?php

declare(strict_types=1);

namespace App\Settings\Infrastructure\Symfony\Configuration\Trait;

use App\Settings\Infrastructure\Symfony\Configuration\RegisterAppDynamicParametersCompilerPassDeprecated;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @deprecated
 */
trait AppDynamicParametersAwareBundle
{
    public function registerDynamicParameters(ContainerBuilder $container, array $classNames): void
    {
        $container->addCompilerPass(
            new RegisterAppDynamicParametersCompilerPassDeprecated($classNames)
        );
    }
}
