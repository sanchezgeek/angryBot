<?php

namespace App;

use App\Infrastructure\DependencyInjection\CompilerPass\AddPositionServiceToCommandsCompilerPass;
use App\Infrastructure\DependencyInjection\CompilerPass\AddSymbolEntityProviderToCommandsCompilerPass;
use App\Infrastructure\DependencyInjection\CompilerPass\AddTradingParametersToCommandsCompilerPass;
use App\Infrastructure\DependencyInjection\CompilerPass\RegisterAppDynamicParametersCompilerPass;
use App\Infrastructure\DependencyInjection\GetServiceHelper;
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

        $container->addCompilerPass(new AddTradingParametersToCommandsCompilerPass());
        $container->addCompilerPass(new AddSymbolEntityProviderToCommandsCompilerPass());
        $container->addCompilerPass(new AddPositionServiceToCommandsCompilerPass());
        $container->addCompilerPass(new RegisterAppDynamicParametersCompilerPass());
        $container->addCompilerPass(new ReplaceCacheCompilerPass());
    }

    public function boot(): void
    {
        parent::boot();

        GetServiceHelper::setContainer($this->getContainer());
    }
}
