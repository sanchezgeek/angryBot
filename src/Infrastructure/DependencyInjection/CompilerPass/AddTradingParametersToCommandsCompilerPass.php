<?php

declare(strict_types=1);

namespace App\Infrastructure\DependencyInjection\CompilerPass;

use App\Trading\Application\Parameters\TradingDynamicParameters;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final readonly class AddTradingParametersToCommandsCompilerPass implements CompilerPassInterface
{
    private const string SYMBOL_ENTITY_PROVIDER_SERVICE_ID = TradingDynamicParameters::class;

    const string DEPENDENT_TAG = 'command.trading_parameters_dependent';

    public function process(ContainerBuilder $container): void
    {
        $tradingParameters = $container->getDefinition(self::SYMBOL_ENTITY_PROVIDER_SERVICE_ID);

        foreach ($container->findTaggedServiceIds(self::DEPENDENT_TAG) as $id => $params) {
            $container->findDefinition($id)->addMethodCall('withTradingParameters', [$tradingParameters]);
        }
    }
}
