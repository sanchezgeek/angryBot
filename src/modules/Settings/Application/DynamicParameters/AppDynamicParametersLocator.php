<?php

declare(strict_types=1);

namespace App\Settings\Application\DynamicParameters;

use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameter;
use Exception;
use ReflectionClass;
use ReflectionMethod;

final class AppDynamicParametersLocator
{
    private array $containers = [];

    /** @var array<string, array<array-key, ReflectionMethod>> */
    private array $groups = [];

    public function register(string $containerClass): void
    {
        $this->containers[] = $containerClass;
    }

    /**
     * @return array<array-key, array{name: string, items: string[]}>
     */
    public function getRegisteredParametersByGroups(): array
    {
        return array_map(static function ($parameters, $groupName) {
            return ['name' => $groupName, 'items' => array_keys($parameters)];
        }, $this->groups, array_keys($this->groups));
    }

    public function initialize(): void
    {
        foreach ($this->containers as $containerClass) {
            foreach ((new ReflectionClass($containerClass))->getMethods() as $methodRef) {
                if (
                    !$methodRef->isPublic()
                    || !$appParameterAttributes = $methodRef->getAttributes(AppDynamicParameter::class)
                ) {
                    continue;
                }

                $attribute = $appParameterAttributes[0];
                $group = $attribute->getArguments()['group'];
                $name = $attribute->getArguments()['name'] ?? $methodRef->getName();

                $this->groups[$group][$name] = $methodRef;
            }
        }
    }

    public function getReferencedMethodReflection(string $group, string $name): ReflectionMethod
    {
        if (!$methodRef = $this->groups[$group][$name] ?? null) {
            throw new Exception('Not found');
        }

        return $methodRef;
    }
}
