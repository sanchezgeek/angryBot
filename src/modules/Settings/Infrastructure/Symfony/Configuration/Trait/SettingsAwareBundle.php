<?php

declare(strict_types=1);

namespace App\Settings\Infrastructure\Symfony\Configuration\Trait;

use App\Settings\Infrastructure\Symfony\Configuration\SettingsCompilerPass;
use App\Settings\Infrastructure\Symfony\Configuration\SettingsDefaultValuesCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

trait SettingsAwareBundle
{
    public function registerSettings(ContainerBuilder $container, ?string $bundleNamespace = null, ?string $path = null): void
    {
        $container->addCompilerPass(
            new SettingsCompilerPass($path, $bundleNamespace . '\Application\Settings')
        );
    }

    public function registerSettingsValues(ContainerBuilder $container, ?string $path = null): void
    {
        $container->addCompilerPass(
            new SettingsDefaultValuesCompilerPass($path)
        );
    }
}
