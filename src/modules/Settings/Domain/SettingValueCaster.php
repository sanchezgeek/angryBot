<?php

declare(strict_types=1);

namespace App\Settings\Domain;

use App\Domain\Value\Percent\Percent;
use App\Settings\Application\Attribute\SettingParametersAttributeReader;
use App\Settings\Application\Contract\SettingKeyAware;
use App\Settings\Domain\Enum\SettingType;

final class SettingValueCaster
{
    private static ?array $formatters = null;

    public static function castToDeclaredType(SettingKeyAware $setting, mixed $value): mixed
    {
        self::initializeFormatters();

        $formatter = self::$formatters[SettingParametersAttributeReader::getSettingType($setting)->name] ?? null;

        return $formatter ? $formatter($value) : (string)$value;
    }

    private static function initializeFormatters(): void
    {
        if (self::$formatters === null) {
            self::$formatters[SettingType::Integer->name] = static fn($value) => intval($value);
            self::$formatters[SettingType::Float->name] = static fn($value) => floatval($value);
            self::$formatters[SettingType::Boolean->name] = static fn($value) => (bool)$value;
            self::$formatters[SettingType::Percent->name] = static fn($value) => Percent::string($value, false);
        }
    }
}
