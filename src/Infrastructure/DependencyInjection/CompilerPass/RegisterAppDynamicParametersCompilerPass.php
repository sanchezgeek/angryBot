<?php

declare(strict_types=1);

namespace App\Infrastructure\DependencyInjection\CompilerPass;

use App\Settings\Application\DynamicParameters\AppDynamicParametersLocator;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterAutowiredArgument;
use App\Worker\AppContext;
use Error;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

final readonly class RegisterAppDynamicParametersCompilerPass implements CompilerPassInterface
{
    private const string LOCATOR_SERVICE_ID = AppDynamicParametersLocator::class;

    const string DYNAMIC_PARAMETER_PROVIDER_TAG = 'dynamicParameters.provider';

    public function process(ContainerBuilder $container): void
    {
        $locator = $container->getDefinition(self::LOCATOR_SERVICE_ID);

        $taggedServiceIds = $container->findTaggedServiceIds(self::DYNAMIC_PARAMETER_PROVIDER_TAG);

        foreach ($taggedServiceIds as $id => $params) {
            try {
                $referencedService = $container->getDefinition($id);
            } catch (ServiceNotFoundException) {}
            $locator->addMethodCall('register', [$id]);

            try {
                $reflection = new ReflectionClass($id);

                try {
                    $constructorParameters = $reflection->getConstructor()->getParameters();
                } catch (Error $e) {
                    /**
                     * @see services_test.yaml
                     *  App\Trading\Application\Parameters\TradingParametersProviderInterface:
                     *      class: App\Trading\Application\Parameters\TradingDynamicParameters
                     *      public: true
                     */
//                    if ($e->getMessage() === 'Call to a member function getParameters() on null' && AppContext::isTest()) {
//                    if ($e->getMessage() === 'Call to a member function getParameters() on null') {
//                        var_dump($e->getMessage());
//                        continue;
//                    }

                    throw $e;
                }

                foreach ($constructorParameters as $constructorParameter) {
                    if ($autowiredAttributes = $constructorParameter->getAttributes(AppDynamicParameterAutowiredArgument::class)) {
                        $container->getDefinition($constructorParameter->getType()->getName())->setPublic(true);
                    }
                }
            } catch (ReflectionException $exception) {
            }
        }

        $locator->addMethodCall('initialize');
    }
}
