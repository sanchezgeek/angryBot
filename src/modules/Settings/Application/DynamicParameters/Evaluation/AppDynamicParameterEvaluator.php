<?php

declare(strict_types=1);

namespace App\Settings\Application\DynamicParameters\Evaluation;

use App\Bot\Domain\ValueObject\Symbol;
use App\Settings\Application\DynamicParameters\AppDynamicParametersLocator;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterEvaluations;
use App\Settings\Application\DynamicParameters\DefaultValues\DefaultValueProviderEnum;
use App\Settings\Application\DynamicParameters\DefaultValues\ParameterDefaultValueProviderInterface;
use App\Settings\Application\DynamicParameters\DefaultValues\Provider\DefaultCurrentPriceProvider;
use BackedEnum;
use InvalidArgumentException;
use ReflectionParameter;
use RuntimeException;
use Symfony\Component\DependencyInjection\Container;

final readonly class AppDynamicParameterEvaluator
{
    public function __construct(
        private Container $container,
        private AppDynamicParametersLocator $parametersLocator
    ) {
    }

    /**
     * @return string[]
     */
    public function getParameterArguments(string $group, string $parameterName): array
    {
        return array_map(static fn(ReflectionParameter $ref) => $ref->getName(), $this->parametersLocator->getReferencedMethodReflection($group, $parameterName)->getParameters());
    }

    public function evaluate(AppDynamicParameterEvaluationEntry $entry): mixed
    {
        $methodReflection = $this->parametersLocator->getReferencedMethodReflection($entry->groupName, $entry->parameterName);

        $arguments = $methodReflection->getParameters();
        $callArguments = [];

        foreach ($arguments as $argumentRef) {
            $callArguments[$argumentRef->getName()] = $this->parseArgument($argumentRef, $entry);
        }

        $obj = $this->container->get($methodReflection->class);
        $callback = [$obj, $methodReflection->getName()];

        return call_user_func_array($callback, $callArguments);
    }

    private function parseArgument(ReflectionParameter $ref, AppDynamicParameterEvaluationEntry $entry): mixed
    {
        $providedValue = $entry->givenUserInput[$ref->getName()] ?: null;

        // @todo if ! and without ?
        if ($providedValue === null) {
            if (!$evaluationAttributes = $ref->getAttributes(AppDynamicParameterEvaluations::class)) {
                throw new InvalidArgumentException('Either value must be provided or DefaultValueProviderEnum specified');
            }

            $defaultValueProviderEnum = $evaluationAttributes[0]->getArguments()['defaultValueProvider'];
            $valueProvider = $this->getDefaultValueProvider($defaultValueProviderEnum);

            return $valueProvider->get($entry->givenUserInput);
        }

        $type = $ref->getType()->getName();
        $parser = match (true) {
            $type === Symbol::class => static fn ($value) => $type::fromShortName(strtoupper($value)),
            is_subclass_of($type, BackedEnum::class) => static fn ($value) => $type::from($value),
            default => static fn($value) => $value,
        };

        return $parser($providedValue);
    }

    private function getDefaultValueProvider(DefaultValueProviderEnum $defaultValueProviderEnum): ParameterDefaultValueProviderInterface
    {
        return match ($defaultValueProviderEnum) {
            DefaultValueProviderEnum::CurrentPrice => $this->container->get(DefaultCurrentPriceProvider::class),
            default => throw new RuntimeException(sprintf('Cannot find default value provider (%s)', $defaultValueProviderEnum->name))
        };
    }
}
