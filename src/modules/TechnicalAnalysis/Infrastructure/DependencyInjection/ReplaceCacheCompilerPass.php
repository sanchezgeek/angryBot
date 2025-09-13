<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Infrastructure\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final readonly class ReplaceCacheCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $technicalAnalysisCacheWrapper = $container->getDefinition('technicalAnalysis.cacheWrapper');

        foreach ($container->findTaggedServiceIds('technicalAnalysis.cache') as $id => $params) {
            $container->findDefinition($id)->addMethodCall('replaceInnerCacheService', [$technicalAnalysisCacheWrapper]);
        }
    }
}
