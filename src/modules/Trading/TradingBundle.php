<?php

declare(strict_types=1);

namespace App\Trading;

use App\Settings\Infrastructure\Symfony\Configuration\Trait\SettingsAwareBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;


final class TradingBundle extends AbstractBundle
{
    use SettingsAwareBundle;

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $this->registerSettings($container, __NAMESPACE__, __DIR__ . '/Application/Settings');

        $this->registerSettingsValues($container,__DIR__ . '/Infrastructure/Symfony/config/trading_settings.yaml');
        $this->registerSettingsValues($container,__DIR__ . '/Infrastructure/Symfony/config/lockInProfit/lock_in_profit_root_settings.yaml');
        $this->registerSettingsValues($container,__DIR__ . '/Infrastructure/Symfony/config/lockInProfit/lock_in_profit_by_stops_settings.yaml');
        $this->registerSettingsValues($container,__DIR__ . '/Infrastructure/Symfony/config/lockInProfit/lock_in_profit_by_fixations_settings.yaml');
    }
}
