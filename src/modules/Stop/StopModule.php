<?php

declare(strict_types=1);

namespace App\Stop;

use App\Settings\Infrastructure\Symfony\Configuration\Trait\SettingsAwareBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class StopModule extends AbstractBundle
{
    use SettingsAwareBundle;

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $this->registerSettings($container, __NAMESPACE__, __DIR__ . '/Application/Settings');
        $this->registerSettingsValues($container,__DIR__ . '/Infrastructure/Symfony/config/stop_settings.yaml');
    }
}
