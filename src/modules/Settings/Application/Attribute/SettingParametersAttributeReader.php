<?php

declare(strict_types=1);

namespace App\Settings\Application\Attribute;

use App\Settings\Application\Contract\SettingKeyAware;
use App\Settings\Domain\Enum\SettingType;
use BackedEnum;
use ReflectionClass;
use ReflectionEnumUnitCase;

final class SettingParametersAttributeReader
{
    public static function getSettingType(SettingKeyAware $obj): SettingType
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

    public static function isSettingNullable(SettingKeyAware $obj): bool
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
