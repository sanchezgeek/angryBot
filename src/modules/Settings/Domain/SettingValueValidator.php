<?php

declare(strict_types=1);

namespace App\Settings\Domain;

use App\Domain\Value\Percent\Percent;
use App\Settings\Application\Attribute\SettingParametersAttributeReader;
use App\Settings\Application\Contract\SettingKeyAware;
use App\Settings\Domain\Enum\SettingType;
use InvalidArgumentException;

final class SettingValueValidator
{
    private static ?array $validators = null;

    public static function validate(SettingKeyAware $setting, mixed $value): bool
    {
        self::initializeValidators();

        $validator = self::$validators[SettingParametersAttributeReader::getSettingType($setting)->name] ?? null;

        return $validator ? $validator($value) : is_string($value);
    }

    private static function initializeValidators(): void
    {
        if (self::$validators === null) {
            self::$validators[SettingType::String->name] = static fn($value) => is_string($value);
            self::$validators[SettingType::Integer->name] = static fn($value) => is_numeric($value);
            self::$validators[SettingType::Float->name] = static fn($value) => is_numeric($value);
            self::$validators[SettingType::Boolean->name] = static fn($value) => is_bool($value);
            self::$validators[SettingType::Percent->name] = static function($value) {
                try {
                    Percent::string($value);
                    return true;
                } catch (InvalidArgumentException) {
                    return false;
                }
            };
        }
    }
}
