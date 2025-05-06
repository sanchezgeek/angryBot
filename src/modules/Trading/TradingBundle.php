<?php

declare(strict_types=1);

namespace App\Trading;

use App\Settings\Infrastructure\Symfony\Configuration\Trait\AppDynamicParametersAwareBundle;
use App\Settings\Infrastructure\Symfony\Configuration\Trait\SettingsAwareBundle;
use App\Trading\Application\Parameters\TradingDynamicParameters;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;


final class TradingBundle extends AbstractBundle
{
    use SettingsAwareBundle;
    use AppDynamicParametersAwareBundle;

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $this->registerSettings($container, __NAMESPACE__, __DIR__ . '/Application/Settings');
        $this->registerDynamicParameters($container, [
            TradingDynamicParameters::class,
        ]);
    }
}
