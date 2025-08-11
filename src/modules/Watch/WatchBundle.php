<?php

declare(strict_types=1);

namespace App\Watch;

use App\Settings\Infrastructure\Symfony\Configuration\Trait\SettingsAwareBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class WatchBundle extends AbstractBundle
{
    use SettingsAwareBundle;

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
    }
}
