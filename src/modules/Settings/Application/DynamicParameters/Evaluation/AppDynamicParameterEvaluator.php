<?php

declare(strict_types=1);

namespace App\Settings\Application\DynamicParameters\Evaluation;

use App\Settings\Application\DynamicParameters\AppDynamicParametersLocator;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterEvaluations;
use App\Settings\Application\DynamicParameters\DefaultValues\DefaultValueProviderEnum;
use App\Settings\Application\DynamicParameters\DefaultValues\ParameterDefaultValueProviderInterface;
use App\Settings\Application\DynamicParameters\DefaultValues\Provider\DefaultCurrentPositionStateProvider;
use App\Settings\Application\DynamicParameters\DefaultValues\Provider\DefaultCurrentPriceProvider;
use App\Settings\Application\DynamicParameters\DefaultValues\Provider\DefaultCurrentTickerProvider;
use App\Settings\Application\DynamicParameters\DefaultValues\Provider\LiquidationHandler\DefaultLiquidationHandlerHandledMessageProvider;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Application\Symbol\SymbolProvider;
use App\Trading\Domain\Symbol\SymbolInterface;
use BackedEnum;
use InvalidArgumentException;
use ReflectionException;
use ReflectionParameter;
use RuntimeException;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

final readonly class AppDynamicParameterEvaluator
{
    public function __construct(
        private Container $container,
        private AppDynamicParametersLocator $parametersLocator,
        private TradingParametersProviderInterface $tradingParametersProvider,
        private SymbolProvider $symbolProvider,
    ) {
    }

    /**
     * @return array{constructorArguments: string[], referencedMethodArguments: string[]}
     */
    public function getArgumentsToEvaluateAllParameters(): array
    {
        $constructorsArguments = [];
        $methodsArguments = [];
        foreach ($this->parametersLocator->getRegisteredParametersByGroups() as ['name' => $groupName, 'items' => $parameters]) {
            foreach ($parameters as $parameterName) {
                $res = $this->getParameterArguments($groupName, $parameterName);

                $constructorsArguments = array_merge($constructorsArguments, $res['constructorArguments']);
                $methodsArguments = array_merge($methodsArguments, $res['referencedMethodArguments']);
            }
        }

        return ['constructorsArguments' => $constructorsArguments, 'methodsArguments' => $methodsArguments];
    }

    /**
     * @return array{constructorArguments: string[], referencedMethodArguments: string[]}
     */
    public function getParameterArguments(string $group, string $parameterName): array
    {
        $methodReflection = $this->parametersLocator->getReferencedMethodReflection($group, $parameterName);
        $methodArgumentsReflections = $methodReflection->getParameters();

        try {
            $this->container->get($methodReflection->class);
            $constructorArguments = [];
        } catch (ServiceNotFoundException) {
            $constructorArgumentsReflections = array_filter(
                $methodReflection->getDeclaringClass()->getConstructor()->getParameters(),
                fn(ReflectionParameter $parameter) => !$this->hasAutowiredConstructParameter($parameter)
            );
            $constructorArguments = $this->getRequiredUserInput($constructorArgumentsReflections);
        }

        $referencedMethodArguments = $this->getRequiredUserInput($methodArgumentsReflections);

        return [
            'constructorArguments' => $constructorArguments,
            'referencedMethodArguments' => $referencedMethodArguments,
        ];
    }

    private function hasAutowiredConstructParameter(ReflectionParameter $parameter): bool
    {
        $name = $parameter->getType()->getName();

        try {
            $this->container->get($name);
            return true;
        } catch (ServiceNotFoundException) {
            return false;
        }
    }

    /**
     * @param ReflectionParameter[] $argumentsReflections
     * @return array
     */
    private function getRequiredUserInput(array $argumentsReflections): array
    {
        $requiredInnerArguments = [];
        foreach ($argumentsReflections as $argumentRef) {
            $requiredInnerArguments = array_merge($requiredInnerArguments, $this->requiredInnerArguments($argumentRef));
        }

        $arguments = array_filter(
            $argumentsReflections,
            static fn (ReflectionParameter $argumentRef) =>
                !($evaluationAttributes = $argumentRef->getAttributes(AppDynamicParameterEvaluations::class))
                || ($evaluationAttributes[0]->getArguments()['skipUserInput'] ?? false) !== true
        );

        $names = array_map(static fn(ReflectionParameter $ref) => $ref->getName(), $arguments);
        $filteredArgumentsToInput = array_combine($names, $names);

        return array_merge($filteredArgumentsToInput, $requiredInnerArguments);
    }

    public function evaluate(AppDynamicParameterEvaluationEntry $entry): mixed
    {
        $methodReflection = $this->parametersLocator->getReferencedMethodReflection($entry->groupName, $entry->parameterName);

        $referencedMethodArguments = $methodReflection->getParameters();
        $callArguments = [];

        foreach ($referencedMethodArguments as $argumentRef) {
            $callArguments[$argumentRef->getName()] = $this->parseArgument($argumentRef, $entry, $entry->methodArgumentsInput);
        }

        try {
            $obj = $this->container->get($methodReflection->class);
        } catch (ServiceNotFoundException) {
            $constructorArgumentsWithUserInput = $methodReflection->getDeclaringClass()->getConstructor()->getParameters();

            $constructorCallArguments = [];
            foreach ($constructorArgumentsWithUserInput as $key => $constructorParameter) {
                if ($this->hasAutowiredConstructParameter($constructorParameter)) {
                    try {
                        $constructorCallArguments[$constructorParameter->getName()] = $this->container->get($constructorParameter->getType()->getName());
                        unset($constructorArgumentsWithUserInput[$key]);
                    } catch (ServiceNotFoundException $e) {}
                }
            }

            foreach ($constructorArgumentsWithUserInput as $argumentRef) {
                $constructorCallArguments[$argumentRef->getName()] = $this->parseArgument($argumentRef, $entry, $entry->constructorArgumentsInput);
            }

            $obj = $methodReflection->getDeclaringClass()->newInstance(...$constructorCallArguments);
        }
        $callback = [$obj, $methodReflection->getName()];

        return call_user_func_array($callback, $callArguments); // return $methodReflection->getClosure($obj)->call($obj);
    }

    private function parseArgument(ReflectionParameter $ref, AppDynamicParameterEvaluationEntry $entry, array $data): mixed
    {
        $argumentName = $ref->getName();
        $providedValue = !isset($data[$argumentName]) ? null : $data[$argumentName];

        // @todo if ! and without ?
        if ($providedValue === null) {

            if ($ref->isDefaultValueAvailable()) {
                return $ref->getDefaultValue();
            }

            $type = $ref->getType()->getName();

            $service = match (true) {
                $type === TradingParametersProviderInterface::class => $this->tradingParametersProvider,
                default => null
            };

            if ($service) {
                return $service;
            }

            if (!$evaluationAttributes = $ref->getAttributes(AppDynamicParameterEvaluations::class)) {
                throw new InvalidArgumentException('Either value must be provided or DefaultValueProviderEnum specified');
            }

            $defaultValueProviderEnum = $evaluationAttributes[0]->getArguments()['defaultValueProvider'];
            $valueProvider = $this->getDefaultValueProvider($defaultValueProviderEnum);

            if ($valueProvider instanceof ParameterDefaultValueProviderInterface) {
                return $valueProvider->get(array_merge($entry->constructorArgumentsInput, $entry->methodArgumentsInput));
            }

            return $valueProvider();
        }

        $type = $ref->getType()->getName();
        $parser = match (true) {
            $argumentName === 'symbol' && !$providedValue instanceof SymbolInterface => fn ($value) => $this->symbolProvider->getOrInitialize(strtoupper($providedValue)),
            is_subclass_of($type, BackedEnum::class) => static fn ($value) => $type::from($value),
            default => static fn($value) => $value,
        };

        return $parser($providedValue);
    }

    private function requiredInnerArguments(ReflectionParameter $ref): array
    {
        if (!$evaluationAttributes = $ref->getAttributes(AppDynamicParameterEvaluations::class)) {
            return [];
        }

        $defaultValueProviderEnum = $evaluationAttributes[0]->getArguments()['defaultValueProvider'];
        $valueProvider = $this->getDefaultValueProvider($defaultValueProviderEnum);

        return $valueProvider instanceof ParameterDefaultValueProviderInterface ? $valueProvider->getRequiredKeys() : [];

    }

    private function getDefaultValueProvider(DefaultValueProviderEnum|string $defaultValueProvider): ParameterDefaultValueProviderInterface|callable
    {
        if (is_string($defaultValueProvider)) {
            return $this->container->get($defaultValueProvider);
        }

        return match ($defaultValueProvider) {
            DefaultValueProviderEnum::CurrentPrice => $this->container->get(DefaultCurrentPriceProvider::class),
            DefaultValueProviderEnum::CurrentTicker => $this->container->get(DefaultCurrentTickerProvider::class),
            DefaultValueProviderEnum::CurrentPositionState => $this->container->get(DefaultCurrentPositionStateProvider::class),
            DefaultValueProviderEnum::LiquidationHandlerHandledMessage => $this->container->get(DefaultLiquidationHandlerHandledMessageProvider::class),
            default => throw new RuntimeException(sprintf('Cannot find default value provider (%s)', $defaultValueProvider->name))
        };
    }
}
