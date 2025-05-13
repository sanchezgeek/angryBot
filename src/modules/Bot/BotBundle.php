<?php

declare(strict_types=1);

namespace App\Bot;

use App\Settings\Infrastructure\Symfony\Configuration\Trait\SettingsAwareBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class BotBundle extends AbstractBundle
{
    use SettingsAwareBundle;

    public function build(ContainerBuilder $container): void
    {
        $this->registerSettings($container, __NAMESPACE__, __DIR__ . '/Application/Settings');
    }
}
