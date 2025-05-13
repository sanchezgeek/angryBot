<?php

declare(strict_types=1);

namespace App\Settings\Infrastructure\Symfony\Configuration;

use App\Settings\Application\DynamicParameters\AppDynamicParametersLocator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

final readonly class RegisterAppDynamicParametersCompilerPass implements CompilerPassInterface
{
    private const LOCATOR_SERVICE_ID = AppDynamicParametersLocator::class;

    public function __construct(private array $classNames)
    {
    }

    public function process(ContainerBuilder $container): void
    {
        $locator = $container->getDefinition(self::LOCATOR_SERVICE_ID);

        foreach ($this->classNames as $className) {
            try {
                $referencedService = $container->getDefinition($className);
            } catch (ServiceNotFoundException) {}
            $locator->addMethodCall('register', [$className]);
        }
    }

}
