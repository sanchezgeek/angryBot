<?php

declare(strict_types=1);

namespace App\Settings\Application\Attribute;

use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Domain\Enum\SettingType;
use BackedEnum;
use ReflectionClass;
use ReflectionEnumUnitCase;

final class SettingParametersAttributeReader
{
    public static function getSettingValueEnumClass(AppSettingInterface $obj): ?string
    {
        if (is_a($obj, BackedEnum::class)) {
            $ref = new ReflectionEnumUnitCase($obj::class, $obj->name);
        } else {
            $ref = new ReflectionClass($obj::class);
        }

        if (
            ($reflectionAttributes = $ref->getAttributes(SettingParametersAttribute::class))
            && ($enumClass = $reflectionAttributes[0]?->getArguments()['enumClass'] ?? null)
        ) {
            return $enumClass;
        }

        return null;
    }

    public static function getSettingType(AppSettingInterface $obj): SettingType
    {
        if (is_a($obj, BackedEnum::class)) {
            $ref = new ReflectionEnumUnitCase($obj::class, $obj->name);
        } else {
            $ref = new ReflectionClass($obj::class);
        }

        if (
            ($reflectionAttributes = $ref->getAttributes(SettingParametersAttribute::class))
            && ($type = $reflectionAttributes[0]?->getArguments()['type'] ?? null)
        ) {
            return $type;
        }

        return SettingType::String;
    }

    public static function isSettingNullable(AppSettingInterface $obj): bool
    {
        if (is_a($obj, BackedEnum::class)) {
            $ref = new ReflectionEnumUnitCase($obj::class, $obj->name);
        } else {
            $ref = new ReflectionClass($obj::class);
        }

        if (
            ($reflectionAttributes = $ref->getAttributes(SettingParametersAttribute::class))
            && ($nullable = $reflectionAttributes[0]?->getArguments()['nullable'] ?? null)
        ) {
            return $nullable;
        }

        return false;
    }
}
