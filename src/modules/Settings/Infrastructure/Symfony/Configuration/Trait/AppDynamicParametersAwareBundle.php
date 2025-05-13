<?php

declare(strict_types=1);

namespace App\Settings\Infrastructure\Symfony\Configuration\Trait;

use App\Settings\Infrastructure\Symfony\Configuration\RegisterAppDynamicParametersCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

trait AppDynamicParametersAwareBundle
{
    public function registerDynamicParameters(ContainerBuilder $container, array $classNames): void
    {
        $container->addCompilerPass(
            new RegisterAppDynamicParametersCompilerPass($classNames)
        );
    }
}
