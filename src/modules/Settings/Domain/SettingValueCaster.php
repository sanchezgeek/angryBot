<?php

declare(strict_types=1);

namespace App\Settings\Domain;

use App\Domain\Value\Percent\Percent;
use App\Settings\Application\Attribute\SettingParametersAttributeReader;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Domain\Enum\SettingType;
use BackedEnum;

final class SettingValueCaster
{
    private static ?array $formatters = null;

    public static function castToDeclaredType(AppSettingInterface $setting, mixed $value): mixed
    {
        self::initializeFormatters();

        $type = SettingParametersAttributeReader::getSettingType($setting);

        if ($type === SettingType::Enum) {
            /** @var BackedEnum $enumClass */
            $enumClass = SettingParametersAttributeReader::getSettingValueEnumClass($setting);

            if ($value instanceof $enumClass) {
                return $value;
            }

            return $enumClass::from($value);
        }

        $formatter = self::$formatters[$type->name] ?? null;

        return $formatter ? $formatter($value) : (string)$value;
    }

    private static function initializeFormatters(): void
    {
        if (self::$formatters === null) {
            self::$formatters[SettingType::Integer->name] = static fn($value) => intval($value);
            self::$formatters[SettingType::Float->name] = static fn($value) => floatval($value);
            self::$formatters[SettingType::Boolean->name] = static fn($value) => (bool)$value;
            self::$formatters[SettingType::Percent->name] = static fn($value) => Percent::string($value, false)->setOutputFloatPrecision(2);
        }
    }
}
