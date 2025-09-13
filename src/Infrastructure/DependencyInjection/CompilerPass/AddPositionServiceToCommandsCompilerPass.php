<?php

declare(strict_types=1);

namespace App\Infrastructure\DependencyInjection\CompilerPass;

use App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearPositionCacheDecoratedService;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final readonly class AddPositionServiceToCommandsCompilerPass implements CompilerPassInterface
{
    private const string SYMBOL_ENTITY_PROVIDER_SERVICE_ID = ByBitLinearPositionCacheDecoratedService::class;

    const string DEPENDENT_TAG = 'command.positionService_dependent';

    public function process(ContainerBuilder $container): void
    {
        $positionService = $container->getDefinition(self::SYMBOL_ENTITY_PROVIDER_SERVICE_ID);

        foreach ($container->findTaggedServiceIds(self::DEPENDENT_TAG) as $id => $params) {
            $container->findDefinition($id)->addMethodCall('withPositionService', [$positionService]);
        }
    }
}
