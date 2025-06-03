<?php

declare(strict_types=1);

namespace App\Settings\Application\DynamicParameters\Evaluation;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Settings\Application\DynamicParameters\AppDynamicParametersLocator;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterEvaluations;
use App\Settings\Application\DynamicParameters\DefaultValues\DefaultValueProviderEnum;
use App\Settings\Application\DynamicParameters\DefaultValues\ParameterDefaultValueProviderInterface;
use App\Settings\Application\DynamicParameters\DefaultValues\Provider\DefaultCurrentPositionStateProvider;
use App\Settings\Application\DynamicParameters\DefaultValues\Provider\DefaultCurrentPriceProvider;
use App\Settings\Application\DynamicParameters\DefaultValues\Provider\DefaultCurrentTickerProvider;
use App\Settings\Application\DynamicParameters\DefaultValues\Provider\LiquidationHandler\DefaultLiquidationHandlerHandledMessageProvider;
use App\Settings\Application\Service\AppSettingsService;
use BackedEnum;
use InvalidArgumentException;
use ReflectionParameter;
use RuntimeException;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

final readonly class AppDynamicParameterEvaluator
{
    public function __construct(
        private Container $container,
        private AppDynamicParametersLocator $parametersLocator
    ) {
    }

    /**
     * @return array{constructorArguments: string[], referencedMethodArguments: string[]}
     */
    public function getParameterArguments(string $group, string $parameterName): array
    {
        $methodReflection = $this->parametersLocator->getReferencedMethodReflection($group, $parameterName);

        try {
            $this->container->get($methodReflection->class);
            $constructorArguments = [];
        } catch (ServiceNotFoundException) {
            $constructorArguments = $methodReflection->getDeclaringClass()->getConstructor()->getParameters();

            $requiredKeys = [];
            foreach ($constructorArguments as $argumentRef) {
                $requiredKeys = array_merge($requiredKeys, $this->requiredInnerArguments($argumentRef));
            }

            $constructorArguments = array_filter($constructorArguments, static fn (ReflectionParameter $argumentRef) =>
                !($evaluationAttributes = $argumentRef->getAttributes(AppDynamicParameterEvaluations::class))
                && ($evaluationAttributes[0]->getArguments()['skipUserInput'] ?? false) !== true
            );

            $initialConstructorArguments = array_map(static fn(ReflectionParameter $ref) => $ref->getName(), $constructorArguments);

            $constructorArguments = array_unique(array_merge($initialConstructorArguments, $requiredKeys));
        }

        $referencedMethodArguments = $this->parametersLocator->getReferencedMethodReflection($group, $parameterName)->getParameters();
        $referencedMethodArguments = array_map(static fn(ReflectionParameter $ref) => $ref->getName(), $referencedMethodArguments);

        return [
            'constructorArguments' => $constructorArguments,
            'referencedMethodArguments' => $referencedMethodArguments
        ];
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
            $constructorArguments = $methodReflection->getDeclaringClass()->getConstructor()->getParameters();
            $constructorCallArguments = [];
            foreach ($constructorArguments as $argumentRef) {
                $constructorCallArguments[$argumentRef->getName()] = $this->parseArgument($argumentRef, $entry, $entry->constructorArgumentsInput);
            }

            $obj = $methodReflection->getDeclaringClass()->newInstance(...$constructorCallArguments);
        }
        $callback = [$obj, $methodReflection->getName()];

//        return $methodReflection->getClosure($obj)->call($obj);
        return call_user_func_array($callback, $callArguments);
    }

    private function parseArgument(ReflectionParameter $ref, AppDynamicParameterEvaluationEntry $entry, array $data): mixed
    {
        $providedValue = !isset($data[$ref->getName()]) ? null : $data[$ref->getName()];

        // @todo if ! and without ?
        if ($providedValue === null) {
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
            $type === SymbolEnum::class => static fn ($value) => $type::fromShortName(strtoupper($value)),
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

    private function getDefaultValueProvider(DefaultValueProviderEnum $defaultValueProviderEnum): ParameterDefaultValueProviderInterface|callable
    {
        return match ($defaultValueProviderEnum) {
            DefaultValueProviderEnum::CurrentPrice => $this->container->get(DefaultCurrentPriceProvider::class),
            DefaultValueProviderEnum::CurrentTicker => $this->container->get(DefaultCurrentTickerProvider::class),
            DefaultValueProviderEnum::CurrentPositionState => $this->container->get(DefaultCurrentPositionStateProvider::class),

            DefaultValueProviderEnum::SettingsProvider => fn() => $this->container->get(AppSettingsService::class),

            DefaultValueProviderEnum::LiquidationHandlerHandledMessage => $this->container->get(DefaultLiquidationHandlerHandledMessageProvider::class),
            default => throw new RuntimeException(sprintf('Cannot find default value provider (%s)', $defaultValueProviderEnum->name))
        };
    }
}
