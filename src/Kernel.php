<?php

namespace App;

use App\Infrastructure\DependencyInjection\CompilerPass\AddSymbolEntityProviderToCommandsCompilerPass;
use App\Infrastructure\DependencyInjection\CompilerPass\RegisterAppDynamicParametersCompilerPass;
use App\TechnicalAnalysis\Infrastructure\DependencyInjection\ReplaceCacheCompilerPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new AddSymbolEntityProviderToCommandsCompilerPass());
        $container->addCompilerPass(new RegisterAppDynamicParametersCompilerPass());
        $container->addCompilerPass(new ReplaceCacheCompilerPass());
    }
}
