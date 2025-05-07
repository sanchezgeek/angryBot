<?php

declare(strict_types=1);

namespace App\Settings\Application\Storage;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Service\SettingAccessor;
use App\Settings\Application\Storage\Dto\AssignedSettingValue;
use App\Settings\Domain\Entity\SettingValue;
use App\Settings\Domain\SettingValueCaster;

final class AssignedSettingValueFactory
{
    public static function byKeyAndValue(AppSettingInterface $setting, string $fullKey, mixed $value, ?string $info = null): AssignedSettingValue
    {
        return new AssignedSettingValue($setting, $fullKey, self::castStoredValue($setting, $value), $info);
    }

    public static function fromEntity(AppSettingInterface $setting, SettingValue $settingValue, ?string $info = null): AssignedSettingValue
    {
        $fullKey = self::buildFullKey($setting, $settingValue->symbol, $settingValue->positionSide);

        return new AssignedSettingValue($setting, $fullKey, self::castStoredValue($setting, $settingValue->value), $info);
    }

    public static function byAccessorAndValue(SettingAccessor $settingAccessor, mixed $value, ?string $info = null): AssignedSettingValue
    {
        $setting = $settingAccessor->setting;
        $fullKey = self::buildFullKey($setting, $settingAccessor->symbol, $settingAccessor->side);

        return new AssignedSettingValue($setting, $fullKey, self::castStoredValue($setting, $value), $info);
    }

    private static function castStoredValue(AppSettingInterface $setting, mixed $storedValue): mixed
    {
        return $storedValue === null ? null : SettingValueCaster::castToDeclaredType($setting, $storedValue);
    }

    public static function buildFullKey(AppSettingInterface $setting, ?Symbol $symbol, ?Side $positionSide): string
    {
        $baseKey = $setting->getSettingKey();

        return match (true) {
            $positionSide !== null => sprintf('%s[symbol=%s][side=%s]', $baseKey, $symbol->value, $positionSide->value),
            $symbol !== null => sprintf('%s[symbol=%s]', $baseKey, $symbol->value),
            default => $baseKey,
        };
    }
}
